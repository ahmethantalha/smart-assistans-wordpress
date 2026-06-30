/**
 * Smart Assistant Widget — ana chatbot balonu ve sütun layout.
 *
 * State: sadece RAM'de (window.__smartAssistantState). localStorage YOK.
 */
( function () {
    'use strict';

    if ( ! window.SmartAssistant ) {
        return;
    }

    const SA = window.SmartAssistant;

    // ===== State =====
    const state = {
        messages: [],        // {role, content, sources?, ts}
        pending: false,
        mode: 'closed',      // 'closed' | 'fab' | 'column'
        contextPostId: 0,    // FAB özetleme için
        history: [],         // AI'a gönderilecek geçmiş (role/content)
        suggestions: [],     // son cevap için 3 önerilen soru (FAB özetleme sonrası)
        activeTool: null,    // { key, label, icon, description, welcomeMsg } — aktif hesaplayıcı
        uiView: 'chat',      // 'chat' | 'tools-list'
    };

    // ===== DOM helpers =====
    function el( tag, attrs = {}, ...children ) {
        const e = document.createElement( tag );
        Object.entries( attrs ).forEach( ( [ k, v ] ) => {
            if ( 'className' === k ) e.className = v;
            else if ( 'style' === k && typeof v === 'object' ) Object.assign( e.style, v );
            else if ( 'dataset' === k && typeof v === 'object' ) Object.entries( v ).forEach( ( [ dk, dv ] ) => e.dataset[ dk ] = dv );
            else if ( k.startsWith( 'on' ) && typeof v === 'function' ) e.addEventListener( k.slice( 2 ).toLowerCase(), v );
            else if ( v !== false && v != null ) e.setAttribute( k, v );
        } );
        children.flat().forEach( ( c ) => {
            if ( c == null ) return;
            if ( typeof c === 'string' || typeof c === 'number' ) e.appendChild( document.createTextNode( String( c ) ) );
            else e.appendChild( c );
        } );
        return e;
    }

    // ===== Ana container =====
    let root, launcher, fab, panel, panelMessages, panelInput, panelHeader, panelSendBtn;
    let columnContainer, fabContainer;
    let panelTitleEl, panelActionsEl, panelToolsList;

    function init() {
        if ( document.getElementById( 'smart-assistant-root' ) ) {
            return;
        }

        root = el( 'div', { id: 'smart-assistant-root', className: 'sa-root sa-mode-closed' } );
        document.body.appendChild( root );

        buildLauncher();
        buildFab();
        buildPanel();
        buildColumnContainer();

        // ESC kapat
        document.addEventListener( 'keydown', ( e ) => {
            if ( 'Escape' === e.key && state.mode !== 'closed' ) {
                close();
            }
        } );

        // Welcome message göster (chat içinde)
        pushMessage( 'assistant', SA.i18n.welcomeMsg );

        // 2 saniye sonra sağ alttaki tanıtım balonunu göster.
        setTimeout( showWelcomeBubble, 2000 );
    }

    // ===== Launcher (sağ alt balon) =====
    function buildLauncher() {
        launcher = el(
            'button',
            {
                className: 'sa-launcher',
                'aria-label': SA.i18n.openChat,
                type: 'button',
                onClick: toggleFab,
            },
            el( 'span', { className: 'sa-launcher-icon', 'aria-hidden': 'true' }, '💬' )
        );
        root.appendChild( launcher );
    }

    // ===== FAB container (single post'larda) =====
    function buildFab() {
        if ( ! SA.isSingle ) {
            return;
        }
        // Buton her zaman görünür — scroll koşulu kaldırıldı (kısa sayfalarda da erişilebilir).
        fabContainer = el( 'div', { className: 'sa-fab-container' },
            el( 'div', { className: 'sa-fab-stack' },
                el( 'button', {
                    className: 'sa-fab sa-fab-summarize',
                    'aria-label': SA.i18n.summarizeTitle,
                    type: 'button',
                    onClick: openSummarize,
                },
                    el( 'span', { className: 'sa-fab-icon' }, '✨' ),
                    el( 'span', { className: 'sa-fab-label' }, SA.i18n.summarizeTitle )
                )
            )
        );
        root.appendChild( fabContainer );

        // Footer'a yaklaşınca gizle (footer ile çakışmasın), scroll up olunca yine göster.
        let ticking = false;
        const updateFab = () => {
            const y = window.scrollY;
            const docH = document.documentElement.scrollHeight;
            const winH = window.innerHeight;
            const distFromBottom = docH - winH - y;
            fabContainer.hidden = distFromBottom < 150;
            ticking = false;
        };
        const onScroll = () => {
            if ( ticking ) return;
            ticking = true;
            requestAnimationFrame( updateFab );
        };
        window.addEventListener( 'scroll', onScroll, { passive: true } );
        updateFab();
    }

    // ===== Panel (chatbot penceresi) =====
    function buildPanel() {
        panelTitleEl   = el( 'span', { className: 'sa-panel-title' } );
        panelActionsEl = el( 'div', { className: 'sa-panel-actions' } );
        panelHeader    = el( 'div', { className: 'sa-panel-header' }, panelTitleEl, panelActionsEl );

        panelMessages  = el( 'div', { className: 'sa-panel-messages' } );
        panelToolsList = el( 'div', { className: 'sa-tools-panel', hidden: true } );

        panelInput = el( 'textarea', {
            className: 'sa-panel-input',
            placeholder: SA.i18n.askPlaceholder,
            rows: 2,
            onKeydown: ( e ) => {
                if ( 'Enter' === e.key && ! e.shiftKey ) {
                    e.preventDefault();
                    submit();
                }
            },
        } );

        panelSendBtn = el(
            'button',
            {
                className: 'sa-panel-send',
                type: 'button',
                onClick: submit,
            },
            SA.i18n.send
        );

        panel = el( 'div', { className: 'sa-panel sa-fab-mode', hidden: true },
            panelHeader,
            panelToolsList,
            panelMessages,
            el( 'div', { className: 'sa-panel-footer' },
                buildClearButton(),
                el( 'div', { className: 'sa-panel-input-row' },
                    panelInput,
                    panelSendBtn
                )
            )
        );
        root.appendChild( panel );

        updatePanelHeader();
    }

    // ===== Testler (hesaplayıcılar) paneli =====

    /**
     * Header'ı duruma göre yeniden çiz: normal sohbet / araç listesi / aktif araç.
     */
    function updatePanelHeader() {
        if ( ! panelTitleEl || ! panelActionsEl ) return;
        panelTitleEl.innerHTML   = '';
        panelActionsEl.innerHTML = '';

        const hasTools = Array.isArray( SA.tools ) && SA.tools.length > 0;

        if ( 'tools-list' === state.uiView ) {
            panelTitleEl.appendChild( document.createTextNode( '🧪 ' ) );
            panelTitleEl.appendChild( el( 'strong', {}, SA.i18n.tests ) );
            panelActionsEl.appendChild( buildIconBtn( '×', SA.i18n.closeChat, () => close(), 'sa-btn-close' ) );
        } else if ( state.activeTool ) {
            panelTitleEl.appendChild( document.createTextNode( state.activeTool.icon + ' ' ) );
            panelTitleEl.appendChild( el( 'strong', {}, state.activeTool.label ) );
            panelActionsEl.appendChild( el( 'button', {
                className: 'sa-tool-back',
                type: 'button',
                onClick: showToolsList,
            }, '← ' + SA.i18n.tests ) );
            panelActionsEl.appendChild( buildIconBtn( '×', SA.i18n.closeChat, () => close(), 'sa-btn-close' ) );
        } else {
            panelTitleEl.appendChild( document.createTextNode( '🤖 ' ) );
            panelTitleEl.appendChild( el( 'strong', {}, SA.i18n.openChat ) );
            if ( hasTools ) {
                panelActionsEl.appendChild( buildIconBtn( '🧪', SA.i18n.tests, showToolsList, 'sa-btn-tools' ) );
            }
            panelActionsEl.appendChild( buildIconBtn( '↗', SA.i18n.expand, () => switchMode( 'column' ), 'sa-btn-expand' ) );
            panelActionsEl.appendChild( buildIconBtn( '×', SA.i18n.closeChat, () => close(), 'sa-btn-close' ) );
        }
    }

    function showToolsList() {
        state.uiView = 'tools-list';
        renderToolsList();
        if ( panelToolsList ) panelToolsList.hidden = false;
        if ( panelMessages ) panelMessages.hidden = true;
        const sugg = panel && panel.querySelector( '.sa-suggestions' );
        if ( sugg ) sugg.hidden = true;
        updatePanelHeader();
    }

    function showChatView() {
        state.uiView = 'chat';
        if ( panelToolsList ) panelToolsList.hidden = true;
        if ( panelMessages ) panelMessages.hidden = false;
        const sugg = panel && panel.querySelector( '.sa-suggestions' );
        if ( sugg ) sugg.hidden = false;
        updatePanelHeader();
    }

    function renderToolsList() {
        if ( ! panelToolsList ) return;
        panelToolsList.innerHTML = '';

        panelToolsList.appendChild( el( 'div', { className: 'sa-tools-hint' }, SA.i18n.testsHint || '' ) );

        ( SA.tools || [] ).forEach( ( tool ) => {
            const card = el(
                'button',
                {
                    className: 'sa-tool-card',
                    type: 'button',
                    onClick: () => startTool( tool ),
                },
                el( 'span', { className: 'sa-tool-icon', 'aria-hidden': 'true' }, tool.icon ),
                el( 'span', { className: 'sa-tool-info' },
                    el( 'span', { className: 'sa-tool-label' }, tool.label ),
                    el( 'span', { className: 'sa-tool-desc' }, tool.description )
                ),
                el( 'span', { className: 'sa-tool-arrow', 'aria-hidden': 'true' }, '›' )
            );
            panelToolsList.appendChild( card );
        } );
    }

    /**
     * Bir aracı başlat: sohbeti sıfırla, sistem prompt'u sunucuda tool key
     * ile eşleştirilecek, kullanıcıya aracın karşılama mesajını göster.
     */
    function startTool( tool ) {
        state.activeTool   = tool;
        state.messages      = [];
        state.history       = [];
        state.suggestions   = [];
        state.contextPostId = 0;

        showChatView();
        renderMessages();
        renderSuggestions();
        pushMessage( 'assistant', tool.welcomeMsg );

        if ( panelInput ) panelInput.focus();
    }

    function buildClearButton() {
        return el(
            'button',
            {
                className: 'sa-panel-clear',
                type: 'button',
                onClick: clearChat,
                title: SA.i18n.clearChat,
            },
            '🗑 ' + SA.i18n.clearChat
        );
    }

    function buildIconBtn( label, title, onClick, extraClass = '' ) {
        return el( 'button', {
            className: 'sa-icon-btn ' + extraClass,
            type: 'button',
            'aria-label': title,
            title,
            onClick,
        }, label );
    }

    // ===== Column container (genişletilmiş mod) =====
    function buildColumnContainer() {
        columnContainer = el( 'aside', { className: 'sa-column', hidden: true, 'aria-label': SA.i18n.openChat },
            el( 'div', { className: 'sa-column-header' },
                el( 'strong', {}, '🤖 ', SA.i18n.openChat ),
                el( 'div', { className: 'sa-column-actions' },
                    buildIconBtn( '↙', SA.i18n.collapse, () => switchMode( 'fab' ), 'sa-btn-collapse' ),
                    buildIconBtn( '×', SA.i18n.closeChat, () => close(), 'sa-btn-close' )
                )
            ),
            el( 'div', { className: 'sa-column-messages' } ),
            el( 'div', { className: 'sa-column-footer' },
                buildClearButton(),
                el( 'div', { className: 'sa-column-input-row' },
                    el( 'textarea', {
                        className: 'sa-column-input',
                        placeholder: SA.i18n.askPlaceholder,
                        rows: 2,
                        onKeydown: ( e ) => {
                            if ( 'Enter' === e.key && ! e.shiftKey ) {
                                e.preventDefault();
                                submitFromColumn();
                            }
                        },
                    } ),
                    el( 'button', {
                        className: 'sa-column-send',
                        type: 'button',
                        onClick: submitFromColumn,
                    }, SA.i18n.send )
                )
            )
        );
        document.body.appendChild( columnContainer );

        // Move messages DOM into the right place when switching modes.
    }

    // ===== Mode switching =====
    function toggleFab() {
        if ( 'fab' === state.mode ) {
            close();
        } else {
            switchMode( 'fab' );
        }
    }

    function switchMode( newMode ) {
        state.mode = newMode;
        root.className = 'sa-root sa-mode-' + newMode;

        // Chat açıldığında welcome bubble'ı kaldır.
        dismissWelcomeBubble();

        // Hide everything.
        if ( panel ) panel.hidden = true;
        if ( columnContainer ) columnContainer.hidden = true;
        if ( launcher ) launcher.style.display = '';

        // Column-mode body class: site content daralır.
        document.body.classList.toggle( 'sa-column-active', 'column' === newMode );

        if ( 'fab' === newMode ) {
            panel.hidden = false;
            panel.className = 'sa-panel sa-fab-mode';
            // Move messages from column → fab panel.
            moveMessagesTo( panelMessages );
            panelInput.focus();
        } else if ( 'column' === newMode ) {
            // Sütun modunda araç listesi paneli yok — sohbet görünümüne dön.
            if ( 'tools-list' === state.uiView ) {
                showChatView();
            }
            columnContainer.hidden = false;
            moveMessagesTo( columnContainer.querySelector( '.sa-column-messages' ) );
            setTimeout( () => {
                const inp = columnContainer.querySelector( '.sa-column-input' );
                if ( inp ) inp.focus();
            }, 100 );
        }
    }

    function openSummarize() {
        if ( SA.postId ) {
            state.contextPostId = SA.postId;
        }
        // Önce paneli aç.
        switchMode( 'fab' );
        // Otomatik özetle.
        setTimeout( () => sendMessage( '' , { summarize: true } ), 200 );
    }

    function close() {
        state.mode = 'closed';
        root.className = 'sa-root sa-mode-closed';
        if ( panel ) panel.hidden = true;
        if ( columnContainer ) columnContainer.hidden = true;
        document.body.classList.remove( 'sa-column-active' );
        // Chat kapandığında tekrar welcome bubble gösterme — kullanıcı kapattı.
    }

    function clearChat() {
        if ( ! confirm( SA.i18n.clearChat + '?' ) ) {
            return;
        }
        const hadTool = !! state.activeTool;
        state.messages = [];
        state.history  = [];
        state.contextPostId = 0;
        state.suggestions = [];
        state.activeTool = null;
        renderMessages();
        renderSuggestions();
        pushMessage( 'assistant', SA.i18n.welcomeMsg );
        if ( hadTool ) updatePanelHeader();
    }

    // ===== Welcome bubble (sayfa açılınca sağ alttaki tanıtım balonu) =====
    let welcomeTimer = null;

    function showWelcomeBubble() {
        if ( ! root ) return;
        if ( state.mode !== 'closed' ) return; // chat zaten açıksa gösterme
        if ( root.querySelector( '.sa-welcome-bubble' ) ) return; // zaten varsa

        const bubble = el(
            'div',
            { className: 'sa-welcome-bubble', role: 'status' },
            el( 'div', { className: 'sa-welcome-bubble-tail' } ),
            el(
                'button',
                {
                    className: 'sa-welcome-bubble-close',
                    'aria-label': SA.i18n.closeChat,
                    type: 'button',
                    onClick: ( e ) => { e.stopPropagation(); dismissWelcomeBubble(); },
                },
                '\u00d7'
            ),
            el( 'div', { className: 'sa-welcome-bubble-text' }, SA.i18n.welcomeBubble ),
            el(
                'button',
                {
                    className: 'sa-welcome-bubble-cta',
                    type: 'button',
                    onClick: () => { switchMode( 'fab' ); dismissWelcomeBubble(); },
                },
                SA.i18n.welcomeCTA
            )
        );
        root.appendChild( bubble );

        // Otomatik kaybolma süresi (12sn).
        welcomeTimer = setTimeout( () => dismissWelcomeBubble(), 12000 );
    }

    function dismissWelcomeBubble() {
        if ( welcomeTimer ) {
            clearTimeout( welcomeTimer );
            welcomeTimer = null;
        }
        if ( ! root ) return;
        const bubble = root.querySelector( '.sa-welcome-bubble' );
        if ( ! bubble ) return;
        bubble.classList.add( 'is-leaving' );
        setTimeout( () => { if ( bubble.parentNode ) bubble.parentNode.removeChild( bubble ); }, 280 );
    }

    // ===== Messages =====
    function pushMessage( role, content, sources = [] ) {
        state.messages.push( { role, content, sources, ts: Date.now() } );
        renderMessages();
    }

    function clearSuggestions() {
        state.suggestions = [];
        renderSuggestions();
    }

    function renderSuggestions() {
        // FAB paneldeki mesajların altına suggestions listesi ekle.
        const container = panelMessages && panelMessages.parentNode
            ? panelMessages.parentNode.querySelector( '.sa-suggestions' )
            : null;
        if ( container ) container.remove();

        if ( ! state.suggestions || ! state.suggestions.length ) return;

        const list = el( 'div', { className: 'sa-suggestions' },
            el( 'div', { className: 'sa-suggestions-title' }, '✨ ' + ( SA.i18n.suggestionsTitle || 'Sorabilirsin' ) )
        );
        state.suggestions.forEach( ( q ) => {
            const chip = el( 'button', {
                className: 'sa-suggestion-chip',
                type: 'button',
                onClick: () => useSuggestion( q ),
            }, q );
            list.appendChild( chip );
        } );

        // FAB panel'in footer'ından önce ekle.
        const footer = panel && panel.querySelector( '.sa-panel-footer' );
        if ( footer ) {
            panel.insertBefore( list, footer );
        } else if ( panelMessages ) {
            panelMessages.parentNode.appendChild( list );
        }
    }

    function useSuggestion( q ) {
        // Input'a yaz ve otomatik gönder.
        state.suggestions = [];
        renderSuggestions();
        if ( panelInput ) {
            panelInput.value = q;
            submit();
        }
    }

    function renderMessages() {
        // FAB panel
        if ( panelMessages ) {
            renderInto( panelMessages );
        }
        // Column panel
        const col = columnContainer && columnContainer.querySelector( '.sa-column-messages' );
        if ( col ) {
            renderInto( col );
        }
    }

    function renderInto( container ) {
        container.innerHTML = '';
        state.messages.forEach( ( m ) => {
            const bubble = el( 'div', { className: 'sa-msg sa-msg-' + m.role } );
            if ( m.role === 'user' ) {
                bubble.textContent = m.content;
            } else {
                // Assistant: markdown-light render + links
                bubble.innerHTML = renderAssistantHtml( m.content );
                if ( m.sources && m.sources.length ) {
                    bubble.appendChild( renderSources( m.sources ) );
                }
            }
            // Her mesajda kopyalama butonu.
            bubble.appendChild( renderCopyButton( m.content ) );
            container.appendChild( bubble );
        } );
        container.scrollTop = container.scrollHeight;
    }

    /**
     * Mesaj kopyalama butonu. Sağ üst köşede, hover'da vurgulanır.
     * Kullanıcı ve AI mesajları için ortak.
     */
    function renderCopyButton( content ) {
        const btn = el( 'button', {
            className: 'sa-msg-copy',
            type: 'button',
            'aria-label': 'Mesajı kopyala',
            title: 'Kopyala',
            onClick: ( ev ) => {
                ev.stopPropagation();
                copyToClipboard( content, btn );
            },
        } );
        // SVG ikon (clipboard).
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
        return btn;
    }

    /**
     * Clipboard'a yaz, başarılıysa "✓" geri bildirimi göster.
     * Modern API (navigator.clipboard.writeText) + fallback (execCommand).
     */
    function copyToClipboard( text, btn ) {
        const showFeedback = ( ok ) => {
            const original = btn.innerHTML;
            if ( ok ) {
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                btn.classList.add( 'is-copied' );
            }
            setTimeout( () => {
                btn.innerHTML = original;
                btn.classList.remove( 'is-copied' );
            }, 1400 );
        };

        // Modern yol.
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then(
                () => showFeedback( true ),
                () => fallbackCopy( text, showFeedback )
            );
        } else {
            fallbackCopy( text, showFeedback );
        }
    }

    function fallbackCopy( text, showFeedback ) {
        try {
            const ta = document.createElement( 'textarea' );
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild( ta );
            ta.select();
            const ok = document.execCommand( 'copy' );
            document.body.removeChild( ta );
            showFeedback( ok );
        } catch ( e ) {
            showFeedback( false );
        }
    }

    function renderAssistantHtml( text ) {
        // 0) Bozuk HTML link kalıntılarını temizle (backend temizleyemediyse bile).
        // AI bazen context'teki <a href="URL" target="_blank" rel="...">TEXT</a>
        // formatını cevabına kopyalarken <a href="URL" kısmını unutup gerisini
        // yapıştırıyor. Bu kalıntılar markdown render'dan ÖNCE temizlenmeli,
        // yoksa otomatik link regex'i (adım 11) bozuk HTML üretir.
        let clean = text;

        // Pattern 1: URL sonrası target="_blank" rel="...">TEXT
        // [^\s<>]+ URL'in içindeki tüm karakterleri (boşluk ve <> hariç) yer,
        // böylece URL içindeki " karakteri de yenir ve sonraki pattern'e geçilir.
        clean = clean.replace(
            /(?:https?:\/\/[^\s<>]+)\s+target="[^"]*"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n.,;!?]|\s*$)/giu,
            '$1'
        );
        // Pattern 2: Sadece target="_blank" rel="...">TEXT (URL öncesinde kesilmiş)
        clean = clean.replace(
            /\s*target="[^"]*"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n.,;!?]|\s*$)/giu,
            ' $1'
        );
        // Pattern 3: Yarım kalmış <a ...> tagı (kapanmamış)
        clean = clean.replace( /<a\s+[^>]*$/gim, '' );
        // Pattern 4: Herhangi bir HTML attribute kalıntısı (target=, rel=, href=)
        // — bunlar backend temizliğinden geçmiş olmalı, ama frontend güvenliği için.
        clean = clean.replace( /\s+(target|rel|href)="[^"]*"/gi, '' );
        // Pattern 5: Açılıp kapanmamış <a ...> > etiketi
        clean = clean.replace( /<\/?a\s*[^>]*>/gi, '' );

        // 1) Önce HTML escape — AI'ın döndüğü metni güvenli hale getir.
        let safe = escapeHtml( clean );

        // 2) Markdown code block ```lang\n...\n``` — içerikteki ** gibi şeyler etkilenmesin.
        safe = safe.replace( /```(\w*)\n([\s\S]*?)\n```/g, function ( _m, lang, code ) {
            const langAttr = lang ? ' data-lang="' + lang + '"' : '';
            return '<pre' + langAttr + '><code>' + code + '</code></pre>';
        } );
        // Tek satır ```...```  (dil belirtilmeden).
        safe = safe.replace( /```([\s\S]*?)```/g, '<pre><code>$1</code></pre>' );

        // 3) Inline code `...`
        safe = safe.replace( /`([^`\n]+)`/g, '<code>$1</code>' );

        // 4) Başlıklar (### önce, ## sonra, # en son — çakışma olmasın).
        safe = safe.replace( /^######\s+(.+)$/gm, '<h6>$1</h6>' );
        safe = safe.replace( /^#####\s+(.+)$/gm, '<h5>$1</h5>' );
        safe = safe.replace( /^####\s+(.+)$/gm, '<h4>$1</h4>' );
        safe = safe.replace( /^###\s+(.+)$/gm, '<h3>$1</h3>' );
        safe = safe.replace( /^##\s+(.+)$/gm, '<h2>$1</h2>' );
        safe = safe.replace( /^#\s+(.+)$/gm, '<h2>$1</h2>' );

        // 5) Bold (**text** veya __text__) — italik'ten önce yap.
        safe = safe.replace( /\*\*([^\*\n]+?)\*\*/g, '<strong>$1</strong>' );
        safe = safe.replace( /(^|[^_\w])__(?!_)([^_\n]+?)__(?!_)/g, '$1<strong>$2</strong>' );

        // 6) Italic (*text* veya _text_) — kelime ortası _'a dikkat (örn. snake_case).
        safe = safe.replace( /(^|[^\*\w])\*([^\*\n]+?)\*/g, '$1<em>$2</em>' );
        safe = safe.replace( /(^|\s)_([^_\n]+?)_(\s|$|[.,;:!?])/g, '$1<em>$2</em>$3' );

        // 7) Linkler [text](url) — XSS guard ile, sadece güvenli protokoller.
        safe = safe.replace( /\[([^\]]+)\]\(([^\s)]+)\)/g, function ( _m, label, url ) {
            if ( /^(https?:|mailto:|#|\/)/i.test( url ) ) {
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            }
            return label; // Güvensiz URL'yi metne çevir.
        } );

        // 8) Blockquote (> text) — satır bazlı state machine (regex + quantifier backtrack sorununu çözer).
        {
            const lines = safe.split( '\n' );
            const out   = [];
            let bqBuf   = [];
            const bqRe  = /^(?:&gt;|>)\s?/;
            const flushBq = () => {
                if ( bqBuf.length ) {
                    out.push( '<blockquote>' + bqBuf.join( '<br>' ) + '</blockquote>' );
                    bqBuf = [];
                }
            };
            for ( const line of lines ) {
                if ( bqRe.test( line ) ) {
                    bqBuf.push( line.replace( bqRe, '' ) );
                } else {
                    flushBq();
                    out.push( line );
                }
            }
            flushBq();
            safe = out.join( '\n' );
        }

        // 9) Bullet list (- item veya * item).
        safe = safe.replace( /(^|\n)((?:[-*]\s+.+(?:\n|$))+)/g, function ( _m, prefix, list ) {
            const items = list.trim().split( /\n/ ).map( ( line ) =>
                '<li>' + line.replace( /^[-*]\s+/, '' ) + '</li>'
            ).join( '' );
            return prefix + '<ul>' + items + '</ul>';
        } );

        // 10) Numbered list (1. item).
        safe = safe.replace( /(^|\n)((?:\d+\.\s+.+(?:\n|$))+)/g, function ( _m, prefix, list ) {
            const items = list.trim().split( /\n/ ).map( ( line ) =>
                '<li>' + line.replace( /^\d+\.\s+/, '' ) + '</li>'
            ).join( '' );
            return prefix + '<ol>' + items + '</ol>';
        } );

        // 11) Otomatik link — düz metin içindeki URL'leri tıklanabilir yap.
        // Markdown linklerden sonra uygula ki [t](u) zaten <a> olmuş olsun.
        // URL non-greedy, trailing punctuation ayrı grup — nokta/virgül vs. dışarıda kalsın.
        // Güvenlik: URL sonunda `"` veya `>` gibi HTML attribute karakterleri varsa otomatik link KAPATMA
        // (bozuk HTML link kalıntısı olabilir).
        safe = safe.replace(
            /(https?:\/\/[^\s<>\[\]"']+?)([.,!?;:)\]]*?)(?=\s|$)/g,
            function ( _m, url, trailing ) {
                // URL'den sonra bir HTML attribute/parantez başlıyorsa bu temiz bir URL değil,
                // otomatik linkleme.
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trailing;
            }
        );

        // 12) Paragraflar — çift satır sonu ile ayrılan blokları <p> ile sar.
        // Önceden oluşturulmuş blok elementleri (h2-h6, ul, ol, pre, blockquote) sarılmasın.
        const blocks = safe.split( /\n{2,}/ );
        safe = blocks.map( ( block ) => {
            block = block.trim();
            if ( ! block ) return '';
            if ( /^<(h[1-6]|ul|ol|pre|blockquote|p)/.test( block ) ) return block;
            return '<p>' + block.replace( /\n/g, '<br>' ) + '</p>';
        } ).join( '' );

        return safe;
    }

    function escapeHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    function renderSources( sources ) {
        const block = el( 'div', { className: 'sa-sources' },
            el( 'div', { className: 'sa-sources-title' }, '📚 ', SA.i18n.sources )
        );
        sources.slice( 0, 5 ).forEach( ( s, i ) => {
            const link = el( 'a', {
                href: s.url || '#',
                target: '_blank',
                rel: 'noopener noreferrer',
                className: 'sa-source',
            }, '[' + ( i + 1 ) + '] ', s.title || '' );
            block.appendChild( link );
        } );
        return block;
    }

    function moveMessagesTo( target ) {
        // Re-render both containers fresh from state.
        renderMessages();
    }

    // ===== Submit =====
    function submit() {
        const msg = ( panelInput.value || '' ).trim();
        if ( ! msg ) return;
        panelInput.value = '';
        sendMessage( msg );
    }

    function submitFromColumn() {
        const inp = columnContainer.querySelector( '.sa-column-input' );
        const msg = ( inp.value || '' ).trim();
        if ( ! msg ) return;
        inp.value = '';
        sendMessage( msg );
    }

    async function sendMessage( text, opts = {} ) {
        if ( state.pending ) return;
        state.pending = true;

        // If summarize mode: hit /summarize, else /chat.
        const isSummarize = opts.summarize === true;

        if ( ! isSummarize && text ) {
            pushMessage( 'user', text );
            state.history.push( { role: 'user', content: text } );
        }

        // Loading bubble.
        const loadingBubble = el( 'div', { className: 'sa-msg sa-msg-assistant sa-loading' }, SA.i18n.thinking );
        const target = ( 'column' === state.mode )
            ? columnContainer.querySelector( '.sa-column-messages' )
            : panelMessages;
        if ( target ) {
            target.appendChild( loadingBubble );
            target.scrollTop = target.scrollHeight;
        }

        try {
            const endpoint = isSummarize
                ? SA.restUrl + 'summarize'
                : SA.restUrl + 'chat';

            const body = {
                nonce: SA.nonce,
                history: state.history,
            };
            if ( isSummarize ) {
                body.post_id = state.contextPostId || SA.postId;
            } else {
                body.message = text;
                if ( state.contextPostId ) body.post_id = state.contextPostId;
                if ( state.activeTool ) body.tool = state.activeTool.key;
            }

            const r = await fetch( endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SA.nonce },
                body: JSON.stringify( body ),
            } );

            const data = await r.json();
            loadingBubble.remove();

            if ( ! r.ok ) {
                pushMessage( 'assistant', ( data && data.message ) || SA.i18n.error );
                return;
            }

            pushMessage( 'assistant', data.reply, data.sources || [] );
            if ( ! isSummarize && text ) {
                state.history.push( { role: 'assistant', content: data.reply } );
            } else if ( isSummarize ) {
                state.history.push( { role: 'assistant', content: data.reply } );
            }

            // Özet sonrası önerilen sorular (Mod 1 ve Mod 2 için geçerli).
            if ( isSummarize && Array.isArray( data.suggestions ) && data.suggestions.length ) {
                state.suggestions = data.suggestions;
                renderSuggestions();
            }
        } catch ( e ) {
            loadingBubble.remove();
            pushMessage( 'assistant', SA.i18n.error + ' (' + ( e.message || '' ) + ')' );
        } finally {
            state.pending = false;
        }
    }

    // ===== Boot =====
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();