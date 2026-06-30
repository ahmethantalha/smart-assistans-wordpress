/**
 * Smart Assistant Admin Test — provider'a ping atar.
 */
( function ( $ ) {
    'use strict';

    $( function () {
        const $btn    = $( '#smart-assistant-test-btn' );
        const $result = $( '#smart-assistant-test-result' );
        const $debug  = $( '#smart-assistant-test-debug' );

        if ( ! $btn.length ) return;

        $btn.on( 'click', function () {
            $btn.prop( 'disabled', true ).text( 'Test ediliyor…' );
            $result.text( '' );
            $debug.hide().text( '' );

            wp.apiFetch( {
                path: '/smart-assistant/v1/test',
                method: 'POST',
            } ).then( ( data ) => {
                $btn.prop( 'disabled', false ).text( 'Provider\'ı Test Et' );
                if ( data.ok ) {
                    $result.html( '<span style="color:#16a34a;font-weight:600;">✓ Çalışıyor</span> — ' +
                        ( data.content || '' ).slice( 0, 80 ) );
                } else {
                    $result.html( '<span style="color:#dc2626;font-weight:600;">✗ Hata</span> ' +
                        ( data.error && data.error.message ? data.error.message : '' ) );
                }
                $debug.show().text( JSON.stringify( data.debug, null, 2 ) );
            } ).catch( ( err ) => {
                $btn.prop( 'disabled', false ).text( 'Provider\'ı Test Et' );
                $result.html( '<span style="color:#dc2626;font-weight:600;">✗ İstek başarısız</span> ' + ( err.message || '' ) );
            } );
        } );
    } );
} )( jQuery );