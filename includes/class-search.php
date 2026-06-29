<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mod 1: Yerleşik WP araması.
 * - MySQL FULLTEXT varsa onu kullanır (daha hızlı).
 * - Yoksa LIKE-based fallback.
 *
 * Tek bir post ID için de "get_post_content" sağlar (Mod 1'de context için).
 */
class Search {

    /**
     * Sorgu için en uygun yazıları getir.
     *
     * @param string $query      Kullanıcı sorusu (Türkçe karakter normalize edilecek).
     * @param int    $post_id    Belirli bir post'a bağlıysa (FAB özetleme).
     * @return array [{id, title, url, excerpt, content}, ...]
     */
    public function search( $query, $post_id = 0 ) {
        $opts = smart_assistant_get_options();

        // Belirli bir post bağlamında çalışıyorsak (FAB özetleme)
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( $post && in_array( $post->post_type, (array) $opts['post_types'], true ) ) {
                return [ $this->format_post( $post ) ];
            }
        }

        // Genel arama.
        // Settings'te 'post_types' boşsa, tüm public CPT'lerde ara.
        $post_types = ! empty( $opts['post_types'] ) ? (array) $opts['post_types'] : get_post_types( [ 'public' => true ] );

        // Türkçe için özel relevance scoring: başlık eşleşmesi 3 puan, içerik 1 puan.
        // MySQL FULLTEXT Türkçe stem yapmaz, bu yüzden daha güvenilir.
        if ( mb_strlen( $query ) >= 3 ) {
            $results = $this->relevance_search( $query, $post_types );
            if ( ! empty( $results ) ) {
                return $this->diversify_results( $results );
            }
        }

        // Fallback: WP_Query (FULLTEXT varsa onu kullanır).
        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $opts['max_results'],
            'no_found_rows'  => true,
            'orderby'        => 'relevance',
            'order'          => 'DESC',
            's'              => $query,
        ];

        $q = new \WP_Query( $args );
        $results = [];

        if ( $q->have_posts() ) {
            foreach ( $q->posts as $post ) {
                $results[] = $this->format_post( $post );
            }
            wp_reset_postdata();
        }

        // Eğer hiç sonuç yoksa, eski fallback LIKE denemesi.
        if ( empty( $results ) && mb_strlen( $query ) >= 3 ) {
            $results = $this->fallback_like_search( $query );
        }

        return $this->diversify_results( $results );
    }

    /**
     * Tek bir post'un içeriğini tam olarak getir (özetleme için).
     */
    public function get_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }
        return $this->format_post( $post, true );
    }

    private function format_post( $post, $full_content = false ) {
        $opts       = smart_assistant_get_options();
        $max_chars  = isset( $opts['max_content_chars'] ) ? (int) $opts['max_content_chars'] : 6000;

        // Saf metin çıkar — DOMDocument ile link/attribute kalıntılarını tamamen sil.
        // wp_strip_all_tags sadece tag'leri siler; geriye "url" target="_blank" rel="...">text
        // gibi attribute text'leri kalır ve AI bunu bozuk markdown'a çevirir.
        $raw = $this->extract_plain_text( $post->post_content );

        // Excerpt: her zaman ilk 300 karakter (kart önizlemesi için).
        $excerpt = mb_substr( $raw, 0, 300 );
        if ( mb_strlen( $raw ) > 300 ) {
            $excerpt .= '…';
        }

        // Content: AI'a gidecek metin — option'dan limit (default 6000 char ~ 1500 token).
        // Sınır aşılırsa "...[içerik devamı var]" notu ile kes.
        $content = $raw;
        $truncated = false;
        if ( ! $full_content && mb_strlen( $content ) > $max_chars ) {
            // Kelime sınırında kes (yarım kelime kalmasın).
            $content = mb_substr( $content, 0, $max_chars );
            $last_space = mb_strrpos( $content, ' ' );
            if ( false !== $last_space && $last_space > $max_chars * 0.9 ) {
                $content = mb_substr( $content, 0, $last_space );
            }
            $content .= "\n\n[…içerik burada kesildi, tam metin için URL'ye gidin]";
            $truncated = true;
        }

        return [
            'id'         => (int) $post->ID,
            'title'      => get_the_title( $post ),
            'url'        => get_permalink( $post ),
            'excerpt'    => $excerpt,
            'content'    => $content,
            'word_count' => max( 1, count( preg_split( '/\s+/u', $raw ) ) ),
            'truncated'  => $truncated,
        ];
    }

    private function has_fulltext() {
        global $wpdb;
        $row = $wpdb->get_row( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name = '{$wpdb->prefix}post_title_fulltext'" );
        return ! empty( $row );
    }

    private function is_mysql_compatible_query( $q ) {
        // FULLTEXT için minimum 4 karakter (innodb_ft_min_token_size).
        return mb_strlen( $q ) >= 4;
    }

    /**
     * Türkçe için özel relevance scoring.
     * MySQL FULLTEXT Türkçe stem yapmaz, bu yüzden burada kelime bazlı
     * LIKE ile her kelime için puan hesaplıyoruz:
     *   - Başlıkta geçen her kelime: 3 puan
     *   - Excerpt'te geçen her kelime: 2 puan
     *   - İçerikte geçen her kelime: 1 puan
     * Sonuçlar puana göre DESC sıralanır, sonra post_date DESC.
     *
     * Sorgu önce stop word'lerden arındırılır ("vücut yağ oranımı hesaplamak istiyorum" -> "vücut yağ oranı hesapla").
     * Tüm kelimeler AND ile aranır, böylece "daha az ama doğru" sonuç gelir.
     */
    private function relevance_search( $query, $post_types ) {
        global $wpdb;

        $opts = smart_assistant_get_options();

        // Türkçe stop word temizliği.
        $stop_words = [
            'bir', 'bu', 'şu', 'o', 've', 'ile', 'için', 'içinde', 'mi', 'mı', 'mu', 'mü',
            'nasıl', 'nedir', 'ne', 'hangi', 'hakkında', 'istiyorum', 'ister', 'etmek', 'et',
            'yapmak', 'yap', 'olarak', 'gibi', 'ama', 'fakat', 'ancak', 'sadece', 'ben',
            'sen', 'biz', 'siz', 'onlar', 'daha', 'en', 'çok', 'az', 'bana', 'sana',
            'benim', 'senin', 'bizim', 'sizin', 'onların', 'şey', 'şeyi', 'şeyler', 'konu',
            'konuda', 'konusu', 'üzerine', 'ait', 'the', 'a', 'an', 'is', 'are', 'was',
            'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'should', 'could', 'can', 'may', 'might', 'must',
        ];
        $cleaned = mb_strtolower( $query );
        $cleaned = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $cleaned );
        $words   = preg_split( '/\s+/u', trim( $cleaned ), -1, PREG_SPLIT_NO_EMPTY );
        $words   = array_filter( $words, function ( $w ) use ( $stop_words ) {
            return mb_strlen( $w ) >= 2 && ! in_array( $w, $stop_words, true );
        } );
        $words   = array_values( array_unique( $words ) );

        if ( empty( $words ) ) {
            return [];
        }

        // Post type listesi SQL için.
        $pt_in = array_map( function ( $pt ) use ( $wpdb ) {
            return "'" . esc_sql( $pt ) . "'";
        }, $post_types );
        $pt_in = implode( ',', $pt_in );

        // Her kelime için puanlama:
        //  - Başlıkta TAM geçerse: 6 puan
        //  - Başlıkta PREFIX geçerse (ilk 4 harf): 3 puan
        //  - Excerpt'te TAM geçerse: 4 puan
        //  - Excerpt'te PREFIX geçerse: 2 puan
        //  - İçerikte TAM geçerse: 2 puan
        //  - İçerikte PREFIX geçerse: 1 puan
        //
        // WHERE koşulu: kelimelerden EN AZ BİRİNİN eşleşmesi yeterli (OR).
        // Bu, "yağ oranımı hesaplamak istiyorum" gibi sorgularda "yağ", "oran"
        // veya "hesap" prefix'inin yazıda "Oranı", "Hesaplama" olarak geçmesi
        // yeterli olur.
        $score_parts = [];
        $where_parts = [];
        $params      = [];

        foreach ( $words as $w ) {
            $like_full   = '%' . $wpdb->esc_like( $w ) . '%';
            $like_prefix = ( mb_strlen( $w ) >= 4 )
                ? '%' . $wpdb->esc_like( mb_substr( $w, 0, 4 ) ) . '%'
                : $like_full;

            $score_parts[] = $wpdb->prepare(
                '(CASE WHEN LOWER(post_title)   LIKE LOWER(%s) THEN 6
                       WHEN LOWER(post_title)   LIKE LOWER(%s) THEN 3 ELSE 0 END) +' .
                '(CASE WHEN LOWER(post_excerpt) LIKE LOWER(%s) THEN 4
                       WHEN LOWER(post_excerpt) LIKE LOWER(%s) THEN 2 ELSE 0 END) +' .
                '(CASE WHEN LOWER(post_content) LIKE LOWER(%s) THEN 2
                       WHEN LOWER(post_content) LIKE LOWER(%s) THEN 1 ELSE 0 END)',
                $like_full, $like_prefix,
                $like_full, $like_prefix,
                $like_full, $like_prefix
            );
            // OR ile: en az 1 kelimenin eşleşmesi yeterli.
            $where_parts[] = $wpdb->prepare(
                '(LOWER(post_title) LIKE LOWER(%s) OR LOWER(post_content) LIKE LOWER(%s) OR LOWER(post_excerpt) LIKE LOWER(%s))',
                $like_full, $like_full, $like_full
            );
            $params[] = $like_full; $params[] = $like_full; $params[] = $like_full;
        }

        $score_expr  = implode( ' + ', $score_parts );
        $where_expr  = implode( ' OR ', $where_parts );  // OR: en az 1 kelime eşleşmesi yeterli
        $params[]    = (int) $opts['max_results'];

        $sql = "SELECT ID, post_title, post_content, post_excerpt,
                       ($score_expr) AS relevance_score
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ($pt_in)
                  AND $where_expr
                ORDER BY relevance_score DESC, post_date DESC
                LIMIT %d";

        $prepared = $wpdb->prepare( $sql, $params );
        $rows = $wpdb->get_results( $prepared );

        $results = [];
        foreach ( (array) $rows as $row ) {
            $post                = new \stdClass();
            $post->ID            = (int) $row->ID;
            $post->post_title    = $row->post_title;
            $post->post_content  = $row->post_content;
            $post->post_excerpt  = $row->post_excerpt;
            $results[]           = $this->format_post( $post );
        }
        return $results;
    }

    /**
     * Source diversity: arama sonuçlarını çeşitlendir.
     * - Aynı kategoriden en fazla 2 sonuç (3+ gelirse yedekler düşürülür).
     * - Aynı yazardan en fazla 2 sonuç.
     * - Çok kısa içerikli (< 200 char) sonuçlar düşürülür.
     * - Aynı post ID'den birden fazla sonuç kesinlikle olmamalı (güvenlik).
     *
     * @param array $results format_post çıktısı array'i
     * @return array Çeşitlendirilmiş sonuçlar (orijinal sıra korunur, relevance korunur)
     */
    private function diversify_results( $results ) {
        if ( empty( $results ) ) {
            return $results;
        }

        $max_per_category = 2;
        $max_per_author   = 2;
        $min_content_len  = 200;

        $by_category = [];
        $by_author   = [];
        $seen_ids    = [];
        $diversified = [];

        foreach ( $results as $r ) {
            // Duplicate ID guard.
            $id = isset( $r['id'] ) ? (int) $r['id'] : 0;
            if ( $id && isset( $seen_ids[ $id ] ) ) {
                continue;
            }

            // Çok kısa içerikli sonuçları atla.
            $content_len = isset( $r['excerpt'] ) ? mb_strlen( $r['excerpt'] ) : 0;
            $content_len = max( $content_len, isset( $r['content'] ) ? mb_strlen( $r['content'] ) : 0 );
            if ( $content_len < $min_content_len ) {
                continue;
            }

            // Kategori sayacı.
            $cats = wp_get_post_terms( $id, 'category', [ 'fields' => 'ids' ] );
            $primary_cat = ! empty( $cats ) && ! is_wp_error( $cats ) ? (int) $cats[0] : 0;
            if ( $primary_cat ) {
                $by_category[ $primary_cat ] = $by_category[ $primary_cat ] ?? 0;
                if ( $by_category[ $primary_cat ] >= $max_per_category ) {
                    continue;
                }
            }

            // Yazar sayacı.
            $author = get_post_field( 'post_author', $id );
            if ( $author ) {
                $by_author[ $author ] = $by_author[ $author ] ?? 0;
                if ( $by_author[ $author ] >= $max_per_author ) {
                    continue;
                }
            }

            // Hepsi OK, ekle.
            if ( $id ) {
                $seen_ids[ $id ] = true;
            }
            if ( $primary_cat ) {
                $by_category[ $primary_cat ]++;
            }
            if ( $author ) {
                $by_author[ $author ]++;
            }
            $diversified[] = $r;
        }

        // Eğer diversity çok agresif olduysa ve neredeyse hiç sonuç kalmadıysa,
        // fallback olarak orijinal sonuçları döndür (en azından relevance korunur).
        if ( count( $diversified ) < max( 1, (int) ceil( count( $results ) / 2 ) ) ) {
            return $results;
        }

        return $diversified;
    }

    private function fallback_like_search( $query ) {
        global $wpdb;
        $opts    = smart_assistant_get_options();
        $post_types = ! empty( $opts['post_types'] )
            ? (array) $opts['post_types']
            : get_post_types( [ 'public' => true ] );
        $pt_in  = array_map( function ( $pt ) use ( $wpdb ) {
            return $wpdb->prepare( '%s', $pt );
        }, $post_types );
        $pt_in  = implode( ',', $pt_in );

        // Türkçe stop word'leri at: "yağ oranımı hesaplamak istiyorum" -> "yağ oranı hesapla"
        $stop_words = [
            'bir', 'bu', 'şu', 'o', 've', 'ile', 'için', 'içinde', 'mi', 'mı', 'mu', 'mü',
            'nasıl', 'nedir', 'ne', 'hangi', 'hakkında', 'istiyorum', 'ister', 'etmek', 'et',
            'yapmak', 'yap', 'olarak', 'gibi', 'ama', 'fakat', 'ancak', 'sadece', 'ben',
            'sen', 'biz', 'siz', 'onlar', 'daha', 'en', 'çok', 'az', 'bana', 'sana',
            'benim', 'senin', 'bizim', 'sizin', 'onların', 'şey', 'şeyi', 'şeyler', 'konu',
            'konuda', 'konusu', 'üzerine', 'ait',
        ];
        $cleaned = mb_strtolower( $query );
        $cleaned = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $cleaned );
        $words   = preg_split( '/\s+/u', trim( $cleaned ), -1, PREG_SPLIT_NO_EMPTY );
        $words   = array_filter( $words, function ( $w ) use ( $stop_words ) {
            return mb_strlen( $w ) >= 2 && ! in_array( $w, $stop_words, true );
        } );
        $words   = array_values( $words );

        // Temizlenmiş kelime listesi ile AND araması (daha doğru eşleşme).
        $clauses = [];
        $params  = [];
        foreach ( $words as $w ) {
            $like = '%' . $wpdb->esc_like( $w ) . '%';
            $clauses[] = '(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)';
            $params[]  = $like;
            $params[]  = $like;
            $params[]  = $like;
        }

        if ( empty( $clauses ) ) {
            // Hiç kelime kalmadıysa orijinal sorgu.
            $like   = '%' . $wpdb->esc_like( $query ) . '%';
            $clauses = [ '(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)' ];
            $params = [ $like, $like, $like ];
        }

        $where = implode( ' AND ', $clauses );
        // Başlık eşleşmesine öncelik ver.
        $title_priority = empty( $words ) ? '%' . $wpdb->esc_like( $query ) . '%' : '%' . $wpdb->esc_like( $words[0] ) . '%';
        $sql    = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($pt_in)
             AND $where
             ORDER BY CASE WHEN post_title LIKE %s THEN 0 ELSE 1 END, post_date DESC
             LIMIT %d",
            array_merge( $params, [ $title_priority, (int) $opts['max_results'] ] )
        );

        $rows = $wpdb->get_results( $sql );
        $results = [];
        foreach ( (array) $rows as $row ) {
            $post                = new \stdClass();
            $post->ID            = (int) $row->ID;
            $post->post_title    = $row->post_title;
            $post->post_content  = $row->post_content;
            $post->post_excerpt  = $row->post_excerpt;
            $results[]           = $this->format_post( $post );
        }
        return $results;
    }

    /**
     * HTML içeriğini saf metne çevirir.
     * wp_strip_all_tags sadece tag'leri siler; geriye "url" target="_blank" rel="...">text
     * gibi bozuk attribute text'leri kalır. DOMDocument ile tüm tag + attribute
     * kalıntıları silinir, sadece düz metin kalır.
     */
    private function extract_plain_text( $html ) {
        if ( '' === $html ) return '';

        // Shortcode'ları kaldır.
        $html = strip_shortcodes( $html );

        // DOMDocument ile parse et, sadece text content al.
        if ( ! class_exists( 'DOMDocument' ) ) {
            // Fallback: en azından HTML entity'leri decode et.
            $text = wp_strip_all_tags( $html );
            $text = preg_replace( '/https?:\/\/[^\s<>"]+/', '', $text ); // URL'leri at
            return trim( preg_replace( '/\s+/u', ' ', $text ) );
        }

        $prev = libxml_use_internal_errors( true );
        $dom  = new \DOMDocument( '1.0', 'UTF-8' );
        // Encoding fix.
        $html = '<?xml encoding="UTF-8">' . $html;
        $dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $text = $dom->textContent;
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }
}