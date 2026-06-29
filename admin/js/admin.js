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
