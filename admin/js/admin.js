/**
 * Smart Assistant Admin — modern UI etkileşimleri.
 *
 * 1) Provider değişince base URL + model otomatik güncelle (mevcut davranış korundu).
 * 2) Sidebar nav: smooth scroll + aktif section vurgusu (IntersectionObserver).
 * 3) Save butonu loading state + kayıt sonrası "Kaydedildi" toast.
 * 4) Provider Test butonu: AJAX yerine REST API kullanır.
 */
( function ( $ ) {
    'use strict';

    /* ============================================================
       Provider değişimi (mevcut mantık korundu, geliştirildi)
       ============================================================ */
    if ( typeof window.SmartAssistantPresets !== 'undefined' ) {
        const presets = window.SmartAssistantPresets;

        $( function () {
            const $provider = $( '#smart_assistant_provider' );
            const $baseUrl  = $( '#smart_assistant_api_base_url' );
            const $model    = $( '#smart_assistant_model' );
            const $datalist = $( '#smart_assistant_model_presets' );

            const isBaseUrlDefault = function () {
                const current = $baseUrl.val();
                return Object.values( presets ).some( p => p.base_url === current );
            };

            const updateDatalist = function ( providerKey ) {
                const preset = presets[ providerKey ];
                if ( ! preset ) return;
                $datalist.empty();
                ( preset.models || [] ).forEach( function ( m ) {
                    $( '<option>' ).attr( 'value', m ).appendTo( $datalist );
                } );
            };

            $provider.on( 'change', function () {
                const key = $( this ).val();
                const preset = presets[ key ];
                if ( ! preset ) return;

                if ( isBaseUrlDefault() || '' === $baseUrl.val() ) {
                    $baseUrl.val( preset.base_url );
                    // Animasyon: değişen alana dikkat çek.
                    $baseUrl.css( { background: '#fef9c3' } ).animate( { backgroundColor: '#fff' }, 600 );
                }
                updateDatalist( key );

                const currentModel = $model.val();
                if ( ! currentModel || ! ( preset.models || [] ).includes( currentModel ) ) {
                    if ( ( preset.models || [] ).length ) {
                        $model.val( preset.models[ 0 ] );
                        $model.css( { background: '#fef9c3' } ).animate( { backgroundColor: '#fff' }, 600 );
                    }
                }
            } );
        } );
    }

    /* ============================================================
       Sidebar nav: smooth scroll + aktif section
       ============================================================ */
    $( function () {
        const $navLinks = $( '.sa-nav-link' );
        const $sections = $( '.sa-card[data-section], section.sa-card[id^="section-"]' );

        // Smooth scroll
        $navLinks.on( 'click', function ( e ) {
            e.preventDefault();
            const target = $( this ).attr( 'data-target' );
            const $target = $( '#' + target );
            if ( $target.length ) {
                $( 'html, body' ).animate( {
                    scrollTop: $target.offset().top - 24,
                }, 350 );
            }
        } );

        // Aktif section'ı vurgula (scroll sırasında)
        const observer = new IntersectionObserver( ( entries ) => {
            entries.forEach( ( entry ) => {
                if ( entry.isIntersecting ) {
                    $navLinks.removeClass( 'is-active' );
                    const id = entry.target.id;
                    $( '.sa-nav-link[data-target="' + id + '"]' ).addClass( 'is-active' );
                }
            } );
        }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 } );

        $sections.each( function () {
            observer.observe( this );
        } );
    } );

    /* ============================================================
       Save: loading state + toast
       ============================================================ */
    $( function () {
        const $form = $( '#smart-assistant-form' );
        const $btn  = $( '#smart-assistant-save-btn' );
        const $status = $( '.sa-save-status' );

        if ( ! $form.length || ! $btn.length ) return;

        $form.on( 'submit', function () {
            $btn.addClass( 'is-saving' );
            $status.removeClass( 'is-visible' ).text( '' );
        } );

        // Alternatif: "REST ile Kaydet" butonu — options.php'yi bypass eder.
        // WP options.php akışı bozukken veya "kaydettim ama değişmedi" izlenimi
        // olduğunda buradan deneyebilirsin. JSON olarak tüm form datasını gönderir.
        $( '#smart-assistant-save-rest' ).on( 'click', function () {
            $btn.addClass( 'is-saving' );
            $status.removeClass( 'is-visible' ).text( 'REST ile kaydediliyor…' );

            // form.serializeArray() nested array için [foo][bar] formatında name üretir.
            // PHP bunu doğrudan $_POST nested array olarak parse eder. Bunu JSON'a çevirirken
            // aynı yapıyı korumalıyız — yoksa sanitize bozulur.
            const formData = $form.serializeArray();
            const payload = {};
            formData.forEach( ( f ) => {
                // name "smart_assistant_options[key]" veya "smart_assistant_options[key][sub][…]"
                const m = f.name.match( /^smart_assistant_options\[(.+?)\](.*)$/ );
                if ( ! m ) return;
                const topKey = m[1];
                const rest = m[2]; // '[sub]' veya ''

                if ( ! payload[ topKey ] ) {
                    payload[ topKey ] = rest ? {} : f.value;
                }

                if ( rest ) {
                    // '[0][key]' gibi iç içe path'i parse et.
                    const path = [];
                    rest.replace( /\[([^\]]*)\]/g, ( _, k ) => path.push( k ) );
                    let cur = payload[ topKey ];
                    for ( let i = 0; i < path.length; i++ ) {
                        const k = path[ i ];
                        if ( i === path.length - 1 ) {
                            cur[ k ] = f.value;
                        } else {
                            if ( ! cur[ k ] || typeof cur[ k ] !== 'object' ) cur[ k ] = {};
                            cur = cur[ k ];
                        }
                    }
                }
            } );

            $.ajax( {
                url: ( window.SmartAssistantAdmin.restUrl || '/wp-json/smart-assistant/v1' ) + '/save-options',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify( payload ),
                headers: {
                    'X-WP-Nonce': ( window.SmartAssistantAdmin && window.SmartAssistantAdmin.nonce ) || '',
                },
                success: function ( data ) {
                    if ( data && data.ok ) {
                        $status.text( '✓ REST ile kaydedildi (tools=' + ( data.tools_after ?? '?' ) +
                            ', mode=' + ( data.mode_after ?? '?' ) +
                            ', cf_id_len=' + ( data.cf_id_len ?? 0 ) + '). Sayfa yenileniyor…' ).addClass( 'is-visible' );
                        setTimeout( function () { location.reload(); }, 800 );
                    } else {
                        $status.text( '✗ REST kayıt başarısız (response: ' + JSON.stringify( data || {} ) + ')' ).addClass( 'is-visible' );
                        $btn.removeClass( 'is-saving' );
                    }
                },
                error: function ( xhr ) {
                    let msg = 'HTTP ' + xhr.status;
                    try { const body = JSON.parse( xhr.responseText ); if ( body && body.message ) msg = body.message; } catch (e) {}
                    $status.text( '✗ REST hata: ' + msg ).addClass( 'is-visible' );
                    $btn.removeClass( 'is-saving' );
                },
            } );
        } );
    } );

        // Sayfa yüklendiğinde: eğer settings_errors notice'ı varsa "Kaydedildi" göster.
        $( function () {
            if ( $( '.notice.notice-success.is-dismissible' ).length ) {
                setTimeout( function () {
                    $status.text( '✓ ' + ( window.SmartAssistantI18n && window.SmartAssistantI18n.saved
                        ? window.SmartAssistantI18n.saved
                        : 'Ayarlar kaydedildi' ) ).addClass( 'is-visible' );
                    setTimeout( function () {
                        $status.removeClass( 'is-visible' );
                    }, 3000 );
                }, 200 );
            }
        } );
    } );

    /* ============================================================
       Provider Test butonu
       ============================================================ */
    $( function () {
        const $btn    = $( '#smart-assistant-test-btn' );
        const $result = $( '#smart-assistant-test-result' );
        const $debug  = $( '#smart-assistant-test-debug' );

        if ( ! $btn.length ) return;

        $btn.on( 'click', function () {
            $btn.prop( 'disabled', true ).css( 'opacity', 0.7 );
            $result.removeClass( 'sa-test-success sa-test-error' ).text( ( window.SmartAssistantI18n && window.SmartAssistantI18n.testing ) || 'Test ediliyor...' );
            $debug.hide().text( '' );

            // options.php kayıt gerekebilir; burada REST API kullanmıyoruz,
            // mevcut /wp-admin/admin-ajax.php akışı yerine, save edilmemiş
            // değerleri FORM'DAN oku ve nonce + REST'e POST'la.
            const formData = $btn.closest( 'section' ).prevAll( 'form' ).length
                ? $btn.closest( 'section' ).prevAll( 'form' ).serializeArray()
                : $( '#smart-assistant-form' ).serializeArray();

            const payload = {};
            formData.forEach( ( f ) => {
                if ( f.name.indexOf( 'smart_assistant_options[' ) === 0 ) {
                    const key = f.name.replace( 'smart_assistant_options[', '' ).replace( ']', '' );
                    payload[ key ] = f.value;
                }
            } );

            if ( typeof SmartAssistantAdmin === 'undefined' || ! SmartAssistantAdmin.restUrl ) {
                $result.addClass( 'sa-test-error' ).text( 'REST URL bulunamadı.' );
                $btn.prop( 'disabled', false ).css( 'opacity', 1 );
                return;
            }

            $.ajax( {
                url: SmartAssistantAdmin.restUrl + 'smart-assistant/v1/test',
                method: 'POST',
                data: JSON.stringify( payload ),
                contentType: 'application/json',
                headers: {
                    'X-WP-Nonce': SmartAssistantAdmin.nonce,
                },
                success: function ( data ) {
                    $btn.prop( 'disabled', false ).css( 'opacity', 1 );
                    if ( data && data.ok ) {
                        $result.addClass( 'sa-test-success' ).text( ( window.SmartAssistantI18n && window.SmartAssistantI18n.testSuccess ) || '✓ Bağlantı başarılı' + ( data.model ? ' (' + data.model + ')' : '' ) );
                    } else {
                        $result.addClass( 'sa-test-error' ).text( ( data && data.message ) || 'Hata oluştu.' );
                    }
                    if ( data && data.debug ) {
                        $debug.text( data.debug ).show();
                    }
                },
                error: function ( xhr ) {
                    $btn.prop( 'disabled', false ).css( 'opacity', 1 );
                    let msg = 'HTTP ' + xhr.status;
                    try {
                        const body = JSON.parse( xhr.responseText );
                        if ( body && body.message ) msg = body.message;
                    } catch ( e ) {}
                    $result.addClass( 'sa-test-error' ).text( msg );
                    if ( xhr.responseText ) {
                        $debug.text( xhr.responseText ).show();
                    }
                },
            } );
        } );
    } );

    /* ============================================================
       Tools management (Testler bölümü — ekle / düzenle / sil)
       ============================================================ */
    $( function () {
        const $list = $( '#sa-tools-list' );
        if ( ! $list.length ) return;

        // Toggle edit form.
        $list.on( 'click', '.sa-tool-toggle', function () {
            const $row = $( this ).closest( '.sa-tool-row' );
            $row.find( '.sa-tool-row-summary' ).hide();
            $row.find( '.sa-tool-edit-fields' ).prop( 'hidden', false );
        } );

        // Collapse edit form.
        $list.on( 'click', '.sa-tool-collapse', function () {
            const $row = $( this ).closest( '.sa-tool-row' );
            updateRowSummary( $row );
            $row.find( '.sa-tool-edit-fields' ).prop( 'hidden', true );
            $row.find( '.sa-tool-row-summary' ).show();
        } );

        // Live-update summary while editing.
        $list.on( 'input change', '.sa-tf-label, .sa-tf-icon, .sa-tf-key, .sa-tf-desc', function () {
            updateRowSummary( $( this ).closest( '.sa-tool-row' ) );
        } );

        function updateRowSummary( $row ) {
            $row.find( '.sa-tool-preview-icon' ).text( $row.find( '.sa-tf-icon' ).val() || '🤖' );
            $row.find( '.sa-tool-preview-label' ).text( $row.find( '.sa-tf-label' ).val() || '(Başlıksız)' );
            $row.find( '.sa-tool-preview-key' ).text( $row.find( '.sa-tf-key' ).val() || '...' );
            $row.find( '.sa-tool-preview-desc' ).text( $row.find( '.sa-tf-desc' ).val() || '' );
        }

        // Delete row.
        $list.on( 'click', '.sa-tool-delete', function () {
            if ( ! window.confirm( 'Bu testi silmek istediğinize emin misiniz?' ) ) return;
            $( this ).closest( '.sa-tool-row' ).remove();
        } );

        // Add new tool.
        $( '#sa-add-tool' ).on( 'click', function () {
            const idx = ( window.saToolsNextIdx = ( window.saToolsNextIdx || 0 ) + 1 );
            const $row = $( saToolRowHtml( idx ) );
            $list.append( $row );
            $row.find( '.sa-tool-row-summary' ).hide();
            $row.find( '.sa-tool-edit-fields' ).prop( 'hidden', false );
            $row[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
        } );

        // Reset to defaults (clears all rows; next save restores defaults from PHP).
        $( '#sa-reset-tools' ).on( 'click', function () {
            if ( ! window.confirm( 'Tüm testler varsayılanlara sıfırlanacak. Şimdi kaydettiğinizde varsayılanlar geri yüklenecek. Devam etmek istiyor musunuz?' ) ) return;
            $list.empty();
        } );

        function saToolRowHtml( idx ) {
            return '<div class="sa-tool-row" data-index="' + idx + '">' +
                '<div class="sa-tool-row-summary">' +
                    '<span class="sa-tool-row-icon sa-tool-preview-icon">🤖</span>' +
                    '<div class="sa-tool-row-info">' +
                        '<strong class="sa-tool-preview-label">(Yeni Test)</strong>' +
                        '<code class="sa-tool-preview-key">...</code>' +
                        '<span class="sa-tool-preview-desc"></span>' +
                    '</div>' +
                    '<div class="sa-tool-row-btns">' +
                        '<button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-toggle">Düzenle</button>' +
                        '<button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-delete" style="color:var(--sa-red-500)">Sil</button>' +
                    '</div>' +
                '</div>' +
                '<div class="sa-tool-edit-fields" hidden>' +
                    '<div class="sa-tool-fields-grid">' +
                        '<label>Anahtar (key)<br>' +
                            '<input type="text" name="smart_assistant_options[tools][' + idx + '][key]" value="" class="regular-text sa-tf-key" placeholder="örn. kalori_hesapla" />' +
                        '</label>' +
                        '<label>İkon (emoji)<br>' +
                            '<input type="text" name="smart_assistant_options[tools][' + idx + '][icon]" value="🤖" class="sa-tf-icon" />' +
                        '</label>' +
                        '<label>Başlık<br>' +
                            '<input type="text" name="smart_assistant_options[tools][' + idx + '][label]" value="" class="large-text sa-tf-label" placeholder="Hesaplayıcı adı" />' +
                        '</label>' +
                        '<label>Açıklama<br>' +
                            '<input type="text" name="smart_assistant_options[tools][' + idx + '][description]" value="" class="large-text sa-tf-desc" placeholder="Kısa açıklama" />' +
                        '</label>' +
                        '<label style="grid-column:1/-1">Karşılama Mesajı<br>' +
                            '<textarea name="smart_assistant_options[tools][' + idx + '][welcome_msg]" rows="2" class="large-text sa-tf-welcome" placeholder="Test başladığında gösterilecek mesaj"></textarea>' +
                        '</label>' +
                        '<label style="grid-column:1/-1">Sistem Prompt\'u<br>' +
                            '<textarea name="smart_assistant_options[tools][' + idx + '][system_prompt]" rows="5" class="large-text sa-tf-prompt" placeholder="AI\'ya verilecek talimatlar..."></textarea>' +
                        '</label>' +
                    '</div>' +
                    '<button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-collapse" style="margin-top:10px">↑ Kapat</button>' +
                '</div>' +
            '</div>';
        }
    } );

    /* ============================================================
       Datalist'e preset modelleri yükle (sayfa yüklendiğinde).
       ============================================================ */
    $( function () {
        if ( typeof window.SmartAssistantPresets === 'undefined' ) return;
        const $provider = $( '#smart_assistant_provider' );
        const $datalist = $( '#smart_assistant_model_presets' );
        if ( ! $provider.length || ! $datalist.length ) return;

        const key = $provider.val();
        const preset = window.SmartAssistantPresets[ key ];
        if ( preset && preset.models ) {
            $datalist.empty();
            preset.models.forEach( ( m ) => {
                $( '<option>' ).attr( 'value', m ).appendTo( $datalist );
            } );
        }
    } );

} )( jQuery );
