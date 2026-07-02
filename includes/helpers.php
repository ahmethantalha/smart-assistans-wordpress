<?php
/**
 * Yardımcı fonksiyonlar.
 *
 * @package SmartAssistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin option'larını döndür (varsayılanlarla).
 */
/**
 * Settings sayfasında TEK BİR section'ı manuel render et.
 * WP'nin do_settings_sections() fonksiyonu 2. parametre almaz ve sayfanın
 * tüm section'larını yazdırır; bu yüzden section başına ayrı çağrı yapamayız.
 * Bunun yerine global $wp_settings_fields üzerinden doğrudan field'ları basıyoruz.
 *
 * @param string $section_id Section ID (örn. 'smart_assistant_mode')
 */
function smart_assistant_render_section( $section_id ) {
    global $wp_settings_fields, $wp_settings_sections;

    $page = 'smart-assistant';
    if ( empty( $wp_settings_fields[ $page ][ $section_id ] ) ) {
        echo '<p style="color:#94a3b8;"><em>Bu bölümde henüz ayar yok.</em></p>';
        return;
    }

    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ( $wp_settings_fields[ $page ][ $section_id ] as $field_id => $field ) {
        $field_title = $field['title'] ?? '';
        $field_args  = $field['args']  ?? [];
        ?>
        <tr>
            <th scope="row">
                <?php if ( $field_title ) : ?>
                    <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field_title ); ?></label>
                <?php endif; ?>
            </th>
            <td>
                <?php
                if ( ! empty( $field['callback'] ) && is_callable( $field['callback'] ) ) {
                    call_user_func( $field['callback'], $field_args );
                }
                ?>
            </td>
        </tr>
        <?php
    }
    echo '</tbody></table>';
}

/**
 * AI kimliğini tek noktadan üret (her yerde aynı değer).
 *
 * @return array {
 *   @type string $name         Görünen ad (örn. "Katkılı Gıda Asistanı")
 *   @type string $greeting     Karşılama mesajı (welcome bubble için)
 *   @type string $tone         Samimi/profesyonel/bilgili
 *   @type string $examples     Few-shot örnekler
 *   @type bool   $show_signature Cevap sonuna imza ekle mi?
 * }
 */
function smart_assistant_get_identity() {
    $opts      = smart_assistant_get_options();
    $site_name = get_bloginfo( 'name' );
    $site_desc = get_bloginfo( 'description' );

    $name = ! empty( $opts['ai_name'] ) ? $opts['ai_name'] : ( $site_name . ' Asistanı' );

    $default_greeting = sprintf(
        'Merhaba! 👋 Ben %s. %s içeriklerinden sana yardımcı olabilirim. Ne sormak istersin?',
        $name,
        $site_name
    );

    return [
        'name'           => $name,
        'greeting'       => ! empty( $opts['ai_greeting'] ) ? $opts['ai_greeting'] : $default_greeting,
        'tone'           => ! empty( $opts['ai_tone'] ) ? $opts['ai_tone'] : 'friendly',
        'examples'       => ! empty( $opts['ai_examples'] ) ? $opts['ai_examples'] : '',
        'show_signature' => ! empty( $opts['show_signature'] ),
        'site_name'      => $site_name,
        'site_desc'      => $site_desc,
    ];
}

/**
 * AI kimliğine dayalı system prompt şablonu üret.
 * Boşsa default kullanıcı system_prompt'unu döndürür.
 *
 * @param string $fallback Mevcut system_prompt (kimlik yoksa bu kullanılır)
 * @return string
 */
function smart_assistant_build_identity_prompt( $fallback = '' ) {
    $id = smart_assistant_get_identity();

    $tone_desc = [
        'friendly'     => 'Samimi, sıcak, günlük konuşma dili. Emoji kullanabilirsin.',
        'professional' => 'Profesyonel, ciddi, kurumsal ton. Emoji KULLANMA.',
        'expert'       => 'Uzman, bilgili, detaylı açıklama yapan. Kanıt ve kaynak göster.',
    ];
    $tone = $tone_desc[ $id['tone'] ] ?? $tone_desc['friendly'];

    $signature_directive = $id['show_signature']
        ? "\n- Her cevabının sonuna imza at: — " . $id['name']
        : '';

    $examples_directive = '';
    if ( ! empty( $id['examples'] ) ) {
        // Few-shot: her satır "S: ...| C: ..." formatında
        $lines = preg_split( '/\n+/', trim( $id['examples'] ) );
        $formatted = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) continue;
            if ( strpos( $line, '|' ) !== false ) {
                list( $q, $a ) = array_map( 'trim', explode( '|', $line, 2 ) );
                $formatted[] = "Kullanıcı: " . ltrim( $q, 'S: ' ) . "\nSen: " . ltrim( $a, 'C: ' );
            }
        }
        if ( ! empty( $formatted ) ) {
            $examples_directive = "\n\nÖRNEK KONUŞMALAR (bu tarzda cevap ver):\n" . implode( "\n\n", $formatted );
        }
    }

    $identity = "Sen " . $id['name'] . " adlı bir " . $id['site_name'] . " web sitesi asistanısın.\n\n"
        . "KİMLİĞİN:\n"
        . "- Adın: " . $id['name'] . "\n"
        . "- Çalıştığın site: " . $id['site_name'] . "\n"
        . ( $id['site_desc'] ? "- Sitenin açıklaması: " . $id['site_desc'] . "\n" : '' )
        . "- Ton: " . $tone . "\n"
        . "- Kullanıcıya her zaman '" . $id['name'] . "' olarak imza at.\n"
        . "- 'Ben bir yapay zeka dil modeliyim' gibi generic cümleler KURMA. Sen " . $id['name'] . "'sın." . $signature_directive
        . $examples_directive;

    // Eğer kullanıcı kendi system_prompt'unu yazmışsa, onu kimlik altına ekle.
    if ( ! empty( $fallback ) ) {
        $identity .= "\n\n" . $fallback;
    }

    return $identity;
}

function smart_assistant_get_options() {
    $defaults = [
        'mode'              => 'simple', // 'simple' | 'open_notebook'
        'provider'          => 'MiniMax', // 'MiniMax' | 'openai' | 'gemini' | 'anthropic'
        'api_key'           => '',
        'group_id'          => '', // MiniMax Token Plan için gerekebilir.
        'api_base_url'      => 'https://api.minimax.io/v1',
        'model'             => 'MiniMax-M3',
        'system_prompt'     => "Sen bir web sitesi asistanısın. Sana verilen KAYNAK yazılara dayanarak kullanıcının sorusunu cevapla.\n\n!!! KESİN KURALLAR — BUNLARI ASLA İHLAL ETME !!!\n1. Sana KAYNAK listesi verildiyse (yukarıdaki İŞTE KULLANILABILIR KAYNAKLAR bölümü), MUTLAKA o kaynaklardan en az birini kullan.\n2. ASLA 'sitede böyle bir şey varsa', 'eğer paylaşırsanız', 'bana bilgi verir misiniz' gibi cümleler KURMA.\n3. ASLA kendi genel bilginle cevap yazma. Sadece KAYNAKLARDAKİ bilgilere dayan.\n4. ASLA 'Bu konuda sitede yazı bulamadım' DEME, eğer tek bir kaynak bile olsa onu özetleyip yönlendir.\n5. KAYNAK YOKSA bile, 'Üzgünüm, sitede bu konuyla ilgili yazı bulamadım' de, kendi bilginle cevap YAZMA.\n\nCEVAP FORMATI (kaynak varsa):\n- İlk cümle: Kaynağın tam adı (başlık)\n- Sonra markdown link: [Başlık](URL)\n- Sonra 2-3 cümleyle içerik özeti\n- Sonra 'Detaylı bilgi için yazıyı okuyabilirsin' gibi kısa kapanış\n\nCEVAP FORMATI (kaynak yoksa):\n- 'Sitede bu konuyla ilgili yazı bulamadım. Farklı bir konuda sorabilir misin?'\n\nGENEL KURALLAR:\n- Bilmediğin bir şeyi uydurma; sadece verilen kaynaklara dayan.\n- İç düşüncelerini, planlarını, adım adım akıl yürütmeni gösterme. <thinking>, <think>, [THINK] gibi etiketler veya 'Düşünüyorum', 'Let me think', 'Hadi bakalım' gibi prefix'ler KULLANMA. Doğrudan cevabı ver.\n- Kısa ve öz cevap ver, gereksiz tekrar yapma.\n- Türkçe cevap ver (kullanıcı Türkçe soruyorsa).\n\nFORMAT: Cevaplarını MARKDOWN formatında ver. Kısa başlıklar (##, ###), listeler (- veya 1.), gerektiğinde **kalın** ve *italik*, kod örnekleri için `inline code` ve ```kod blokları``` kullan. Markdown render edileceği için düz metin yerine bu formatı tercih et.",
        'temperature'       => 0.2,
        'max_tokens'        => 800,
        'post_types'        => [ 'post', 'page' ],  // Tüm public CPT'lerde arama için: get_post_types(['public' => true])
        'max_results'       => 5,
        'max_content_chars' => 6000,  // Her yazıdan AI'a gönderilecek max karakter (~1500 token).
        'rate_limit_per_min' => 20,
        'open_notebook_url' => '',
        'open_notebook_notebook_id' => '',  // Mod 2 için ON notebook UUID.
        'on_strategy_model'   => '',       // Opsiyonel: boşsa ON default.
        'on_answer_model'     => '',       // Opsiyonel: boşsa ON default.
        'on_final_answer_model' => '',     // Opsiyonel: boşsa ON default.
        'enable_abilities'  => true,
        // AI Kimliği.
        'ai_name'         => '',           // Boşsa site adından otomatik üretilir.
        'ai_greeting'     => '',           // Boşsa otomatik: "Merhaba! Ben [AI_NAME], ..."
        'ai_tone'         => 'friendly',   // 'friendly' | 'professional' | 'expert'
        'ai_examples'     => '',           // Few-shot örnekler (textarea, her satır "S: ...| C: ...").
        'show_signature'  => false,        // Cevap sonuna "— [AI_NAME]" imzası.
    ];
    $opts = get_option( 'smart_assistant_options', [] );

    // === Migration: Eski kullanıcılara page desteği otomatik ekle. ===
    // Kullanıcı bilinçli olarak kaldırmışsa tekrar eklemeyiz.
    if ( ! empty( $opts ) && ! empty( $opts['post_types'] ) && is_array( $opts['post_types'] )
         && ! in_array( 'page', $opts['post_types'], true )
         && post_type_exists( 'page' )
         && ! get_option( 'smart_assistant_page_skipped', false ) ) {
        $opts['post_types'][] = 'page';
        update_option( 'smart_assistant_options', $opts );
    }

    // === Migration: Eski kullanıcılarda kırık URL varsa otomatik düzelt. ===
    if ( isset( $opts['api_base_url'] ) && false !== strpos( $opts['api_base_url'], 'api.MiniMax.chat' ) ) {
        $opts['api_base_url'] = 'https://api.minimax.io/v1';
        update_option( 'smart_assistant_options', $opts );
    }

    return wp_parse_args( $opts, $defaults );
}

/**
 * Provider için varsayılan base URL ve model listesi.
 */
function smart_assistant_get_provider_presets() {
    return [
        'MiniMax' => [
            'label'    => 'MiniMax (OpenAI uyumlu)',
            'base_url' => 'https://api.minimax.io/v1',
            'models'   => [ 'MiniMax-M3', 'MiniMax-M2.5', 'MiniMax-Text-01' ],
            'auth'     => 'bearer',
            'note'     => 'Token Plan için Group ID alanını da doldurun.',
        ],
        'openai' => [
            'label'    => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'models'   => [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini' ],
            'auth'     => 'bearer',
        ],
        'gemini' => [
            'label'    => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'models'   => [ 'gemini-2.0-flash', 'gemini-2.5-flash-preview-05-20', 'gemini-1.5-pro', 'gemini-1.5-flash' ],
            'auth'     => 'query',
            'note'     => 'API Key\'i Gemini API Keys sayfasından alın. Base URL değiştirilmemeli.',
        ],
        'anthropic' => [
            'label'    => 'Anthropic Claude',
            'base_url' => 'https://api.anthropic.com',
            'models'   => [ 'claude-sonnet-4-6', 'claude-opus-4-8', 'claude-haiku-4-5-20251001' ],
            'auth'     => 'x-api-key',
            'note'     => 'API Key\'i console.anthropic.com\'dan alın. Base URL değiştirilmemeli.',
        ],
    ];
}

/**
 * Tek bir option'ı güncelle.
 */
function smart_assistant_update_option( $key, $value ) {
    $opts = smart_assistant_get_options();
    $opts[ $key ] = $value;
    update_option( 'smart_assistant_options', $opts );
}

/**
 * Rate limit transient key.
 */
function smart_assistant_rate_limit_key( $ip ) {
    return 'smart_assistant_rl_' . md5( $ip );
}

/**
 * Basit log.
 */
function smart_assistant_log( $message, $level = 'info' ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( sprintf( '[SmartAssistant][%s] %s', strtoupper( $level ), $message ) );
    }
}

/**
 * Mevcut sayfanın FAB için uygun olup olmadığını döndürür.
 * Settings'te seçili post type'lardan biri mi kontrol eder.
 */
function smart_assistant_current_supports_fab() {
    if ( ! function_exists( 'is_singular' ) ) {
        return false;
    }
    if ( ! is_singular() ) {
        return false;
    }
    $opts      = smart_assistant_get_options();
    $post_type = get_post_type();
    return $post_type && in_array( $post_type, (array) $opts['post_types'], true );
}

/**
 * Varsayılan araç tanımları. Yönetici panelinden özelleştirilebilir.
 *
 * @return array<string, array>
 */
function smart_assistant_get_default_tools() {
    return [
        'tdee' => [
            'label'         => __( 'Kalori & TDEE', 'smart-assistant' ),
            'description'   => __( 'Günlük enerji ihtiyacını hesapla', 'smart-assistant' ),
            'icon'          => '🔥',
            'welcome_msg'   => __( 'Merhaba! Günlük kalori ihtiyacınızı hesaplamak için birkaç soru soracağım. Başlayalım — cinsiyetiniz nedir? (Erkek / Kadın)', 'smart-assistant' ),
            'system_prompt' => 'Sen bir TDEE (Günlük Toplam Enerji Harcaması) hesaplayıcısısın. Kullanıcıyla Türkçe konuş, nazik ve motive edici bir ton kullan. Sırayla şu bilgileri sor — HER SEFERİNDE YALNIZCA BİR SORU: cinsiyet (erkek/kadın), yaş, boy (cm), kilo (kg), aktivite seviyesi. Aktivite seviyesini sorarken seçenekleri açıkla: hareketsiz (masa başı iş, neredeyse hiç egzersiz yok), hafif aktif (haftada 1-3 gün hafif egzersiz), orta aktif (haftada 3-5 gün orta egzersiz), çok aktif (haftada 6-7 gün yoğun egzersiz), ekstra aktif (günde 2 antrenman veya ağır fiziksel iş). Tüm bilgileri aldıktan sonra Harris-Benedict formülüyle BMR hesapla: Erkek için BMR = 88.362 + (13.397 × kilo) + (4.799 × boy) - (5.677 × yaş); Kadın için BMR = 447.593 + (9.247 × kilo) + (3.098 × boy) - (4.330 × yaş). TDEE = BMR × aktivite çarpanı (hareketsiz=1.2, hafif aktif=1.375, orta aktif=1.55, çok aktif=1.725, ekstra aktif=1.9). Sonucu net ve güzel formatlı sun: BMR, TDEE ve üç kalori hedefi (kilo verme için TDEE-500, koruma için TDEE, kilo alma için TDEE+500). Ardından kullanıcının ek sorularına yanıt ver. Site içeriğine başvurma, sadece bu hesaplamayı yap.',
        ],
        'body_fat' => [
            'label'         => __( 'Vücut Yağ Oranı', 'smart-assistant' ),
            'description'   => __( 'US Navy yöntemiyle yağ yüzdesini ölç', 'smart-assistant' ),
            'icon'          => '⚖️',
            'welcome_msg'   => __( 'Vücut yağ oranınızı US Navy yöntemiyle hesaplayacağım. Cinsiyetiniz nedir? (Erkek / Kadın)', 'smart-assistant' ),
            'system_prompt' => 'Sen bir vücut yağ oranı hesaplayıcısısın. Kullanıcıyla Türkçe konuş. US Navy yöntemini kullanacaksın. Sırayla sor — HER SEFERİNDE YALNIZCA BİR SORU: cinsiyet, boy (cm), bel çevresi (göbeğin en ince noktasından ölçülen, cm), boyun çevresi (cm). Kullanıcı kadınsa ek olarak kalça çevresini (cm) de sor. Formüller: Erkek için %Yağ = 86.010 × log10(bel − boyun) − 70.041 × log10(boy) + 36.76; Kadın için %Yağ = 163.205 × log10(bel + kalça − boyun) − 97.684 × log10(boy) − 78.387 (tüm ölçüler cm). Sonucu ve kategorisini belirt: Esansiyel yağ (Erkek 2-5%, Kadın 10-13%), Atletik (Erkek 6-13%, Kadın 14-20%), Fitness (Erkek 14-17%, Kadın 21-24%), Kabul edilebilir (Erkek 18-24%, Kadın 25-31%), Obez (Erkek ≥25%, Kadın ≥32%). Sağlıklı aralığa ulaşmak için kısa, pratik öneriler sun. Site içeriğine başvurma, sadece bu hesaplamayı yap.',
        ],
        'bmi' => [
            'label'         => __( 'BMI Hesaplayıcı', 'smart-assistant' ),
            'description'   => __( 'Beden kitle indeksini öğren', 'smart-assistant' ),
            'icon'          => '📊',
            'welcome_msg'   => __( 'BMI (Beden Kitle İndeksi) hesaplayacağım. Önce boyunuzu cm cinsinden yazar mısınız?', 'smart-assistant' ),
            'system_prompt' => 'Sen bir BMI (Beden Kitle İndeksi) hesaplayıcısısın. Kullanıcıyla Türkçe konuş. Sırayla sor — HER SEFERİNDE YALNIZCA BİR SORU: boy (cm), kilo (kg). BMI = kilo ÷ (boy/100)². Sonucu ve kategorisini belirt: Zayıf (<18.5), Normal (18.5-24.9), Fazla kilolu (25-29.9), Obez I (30-34.9), Obez II (≥35). BMI\'nin genel bir gösterge olduğunu, kas kütlesi yoğun kişilerde yanıltıcı olabileceğini kısaca belirt. Ardından kullanıcının ek sorularına yanıt ver. Site içeriğine başvurma, sadece bu hesaplamayı yap.',
        ],
    ];
}

/**
 * Widget'taki "Testler" panelinde gösterilen interaktif hesaplayıcı tanımları.
 *
 * Önce veritabanındaki özel araçlara bakar; yoksa varsayılanları döndürür.
 * system_prompt frontend'e ASLA gönderilmez; yalnızca 'tool' key'i REST isteğinde
 * gidip gelir, AIClient sunucu tarafında eşleştirir.
 *
 * @return array<string, array>
 */
function smart_assistant_get_tools() {
    $opts     = smart_assistant_get_options();
    $db_tools = $opts['tools'] ?? [];

    if ( ! empty( $db_tools ) && is_array( $db_tools ) ) {
        $tools = [];
        foreach ( $db_tools as $t ) {
            if ( ! is_array( $t ) ) {
                continue;
            }
            $key = sanitize_key( $t['key'] ?? '' );
            if ( '' === $key ) {
                continue;
            }
            $tools[ $key ] = [
                'label'         => $t['label'] ?? '',
                'description'   => $t['description'] ?? '',
                'icon'          => $t['icon'] ?? '🤖',
                'welcome_msg'   => $t['welcome_msg'] ?? '',
                'system_prompt' => $t['system_prompt'] ?? '',
            ];
        }
        if ( ! empty( $tools ) ) {
            return apply_filters( 'smart_assistant_tools', $tools );
        }
    }

    return apply_filters( 'smart_assistant_tools', smart_assistant_get_default_tools() );
}