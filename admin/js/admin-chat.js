/**
 * Smart Assistant Admin Chat — ayarlar sayfasındaki mini sohbet testi.
 *
 * /admin-chat endpoint'ini kullanır; gerçek Mod 1/2 akışını admin panelinden
 * dener (kaydetmeden önce davranışı görmek için). Geçmiş client-side tutulur,
 * sayfa yenilenince sıfırlanır (frontend genel sohbet gibi kasıtlı olarak).
 */
( function ( $ ) {
    'use strict';

    $( function () {
        const $root = $( '#sa-admin-chat' );
        if ( ! $root.length ) return;

        const i18n = ( window.SmartAssistantAdmin && window.SmartAssistantAdmin.i18n ) || {};

        const $messages = $root.find( '.sa-chat-messages' );
        const $input    = $root.find( '.sa-chat-input' );
        const $send     = $root.find( '.sa-chat-send' );
        const $clear    = $root.find( '.sa-chat-clear' );

        // Geçmiş: { role: 'user'|'assistant', content: '...' }[]
        const history = [];

        function escapeHtml( s ) {
            return ( s || '' )
                .replace( /&/g, '&amp;' )
                .replace( /</g, '&lt;' )
                .replace( />/g, '&gt;' )
                .replace( /"/g, '&quot;' )
                .replace( /'/g, '&#39;' );
        }

        function renderEmptyState() {
            $messages.html(
                '<div class="sa-chat-empty">' +
                escapeHtml( i18n.chatEmpty || 'Henüz mesaj yok. Bir soru sorarak başlayın.' ) +
                '</div>'
            );
        }

        function appendMessage( role, content ) {
            // İlk mesajla boş durumu kaldır.
            $messages.find( '.sa-chat-empty' ).remove();
            const cls = 'sa-chat-msg sa-chat-msg-' + role;
            const $el = $( '<div></div>' )
                .addClass( cls )
                .html( '<div class="sa-chat-bubble">' + escapeHtml( content ) + '</div>' );
            $messages.append( $el );
            $messages.scrollTop( $messages[ 0 ].scrollHeight );
        }

        function appendSources( sources ) {
            if ( ! Array.isArray( sources ) || ! sources.length ) return;
            const items = sources.slice( 0, 5 ).map( ( s ) => {
                const title = s.title || s.name || 'Kaynak';
                const url   = s.url   || '#';
                return '<li><a href="' + escapeHtml( url ) + '" target="_blank" rel="noopener">' +
                       escapeHtml( title ) + '</a></li>';
            } ).join( '' );
            const $el = $( '<div class="sa-chat-sources"><div class="sa-chat-sources-title">' +
                '🔗 Kaynaklar</div><ul>' + items + '</ul></div>' );
            $messages.append( $el );
            $messages.scrollTop( $messages[ 0 ].scrollHeight );
        }

        function setBusy( busy ) {
            $input.prop( 'disabled', busy );
            $send.prop( 'disabled', busy );
            $clear.prop( 'disabled', busy );
            if ( busy ) {
                $messages.find( '.sa-chat-thinking' ).remove();
                $messages.append(
                    '<div class="sa-chat-msg sa-chat-msg-thinking">' +
                    '<div class="sa-chat-bubble">' +
                    escapeHtml( i18n.chatThinking || 'Düşünüyor…' ) +
                    '</div></div>'
                );
                $messages.scrollTop( $messages[ 0 ].scrollHeight );
            } else {
                $messages.find( '.sa-chat-thinking' ).remove();
            }
        }

        function sendMessage() {
            const text = ( $input.val() || '' ).trim();
            if ( ! text ) return;

            appendMessage( 'user', text );
            history.push( { role: 'user', content: text } );
            $input.val( '' );

            setBusy( true );

            wp.apiFetch( {
                path: '/smart-assistant/v1/admin-chat',
                method: 'POST',
                data: {
                    message: text,
                    history: history.slice( -10 ), // son 10 mesaj
                },
            } ).then( ( data ) => {
                setBusy( false );
                const reply = data.reply || data.content || '';
                if ( reply ) {
                    appendMessage( 'assistant', reply );
                    history.push( { role: 'assistant', content: reply } );
                } else {
                    appendMessage( 'assistant', '(boş cevap döndü)' );
                }
                if ( data.sources ) {
                    appendSources( data.sources );
                }
            } ).catch( ( err ) => {
                setBusy( false );
                const msg = ( err && err.message )
                    ? err.message
                    : ( err && err.code ? err.code : 'İstek başarısız' );
                appendMessage( 'assistant', '⚠️ ' + msg );
            } );
        }

        $send.on( 'click', sendMessage );
        $input.on( 'keydown', function ( e ) {
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                sendMessage();
            }
        } );
        $clear.on( 'click', function () {
            history.length = 0;
            renderEmptyState();
        } );

        renderEmptyState();
    } );
} )( jQuery );
