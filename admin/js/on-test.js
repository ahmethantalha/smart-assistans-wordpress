/**
 * Smart Assistant Admin — Open Notebook bağlantı testi.
 *
 * CF Access Service Token header'ları zaten PHP tarafında (OpenNotebook::build_headers)
 * eklendiği için, JS sadece endpoint'i tetikler; sağlık/yetki başarısızsa
 * sunucudan gelen hatayı olduğu gibi gösterir.
 */
( function ( $ ) {
    'use strict';

    $( function () {
        const $btn    = $( '#smart-assistant-on-test-btn' );
        if ( ! $btn.length ) return;

        const $result = $( '#smart-assistant-on-test-result' );
        const $debug  = $( '#smart-assistant-on-test-debug' );

        $btn.on( 'click', function () {
            $btn.prop( 'disabled', true );
            $result.text( '' );
            $debug.hide().text( '' );

            wp.apiFetch( {
                path: '/smart-assistant/v1/on-test',
                method: 'POST',
            } ).then( ( data ) => {
                $btn.prop( 'disabled', false );
                if ( data.ok ) {
                    const i18n = ( window.SmartAssistantAdmin && window.SmartAssistantAdmin.i18n ) || {};
                    $result.html(
                        '<span style="color:#16a34a;font-weight:600;">' +
                        ( i18n.onSuccess || '✓ Open Notebook\'e erişildi' ) +
                        '</span> — <strong>' + ( data.count || 0 ) + '</strong> notebook'
                    );
                    $debug.show().text( JSON.stringify( data.debug, null, 2 ) );
                } else {
                    const i18n = ( window.SmartAssistantAdmin && window.SmartAssistantAdmin.i18n ) || {};
                    $result.html(
                        '<span style="color:#dc2626;font-weight:600;">' +
                        ( i18n.onFail || '✗ Bağlantı başarısız' ) +
                        '</span> ' + ( data.error && data.error.message ? data.error.message : '' )
                    );
                    if ( data.debug ) {
                        $debug.show().text( JSON.stringify( data.debug, null, 2 ) );
                    }
                }
            } ).catch( ( err ) => {
                $btn.prop( 'disabled', false );
                $result.html(
                    '<span style="color:#dc2626;font-weight:600;">✗ İstek başarısız</span> ' +
                    ( err.message || '' )
                );
            } );
        } );
    } );
} )( jQuery );
