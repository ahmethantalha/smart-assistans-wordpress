<?php
/**
 * Settings page view — modern UI.
 *
 * @package SmartAssistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$opts = smart_assistant_get_options();
$mode = $opts['mode'] ?? 'simple';
$has_on = ! empty( $opts['open_notebook_url'] ) && ! empty( $opts['open_notebook_notebook_id'] );
$provider_presets = function_exists( 'smart_assistant_get_provider_presets' ) ? smart_assistant_get_provider_presets() : [];

// Section listesi (id => başlık).
$sections = [
    'smart_assistant_mode'     => __( 'Çalışma Modu', 'smart-assistant' ),
    'smart_assistant_general'  => __( 'AI Ayarları', 'smart-assistant' ),
    'smart_assistant_content'  => __( 'İçerik Ayarları', 'smart-assistant' ),
    'smart_assistant_advanced' => __( 'Gelişmiş', 'smart-assistant' ),
];
?>
<div class="sa-app">
    <div class="sa-bg-orbs" aria-hidden="true">
        <span class="sa-orb sa-orb-1"></span>
        <span class="sa-orb sa-orb-2"></span>
        <span class="sa-orb sa-orb-3"></span>
    </div>

    <aside class="sa-sidebar">
        <div class="sa-brand">
            <div class="sa-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2a3 3 0 0 0-3 3v1a3 3 0 0 0-3 3v.5A2.5 2.5 0 0 0 3.5 12 2.5 2.5 0 0 0 6 14.5V15a3 3 0 0 0 3 3v1a3 3 0 0 0 6 0v-1a3 3 0 0 0 3-3v-.5a2.5 2.5 0 0 0 2.5-2.5A2.5 2.5 0 0 0 18 9.5V9a3 3 0 0 0-3-3V5a3 3 0 0 0-3-3z"/>
                    <circle cx="12" cy="12" r="2"/>
                </svg>
            </div>
            <div>
                <div class="sa-brand-name">Smart Assistant</div>
                <div class="sa-brand-tag">v<?php echo esc_html( defined( 'SMART_ASSISTANT_VERSION' ) ? SMART_ASSISTANT_VERSION : '0.2.8' ); ?></div>
            </div>
        </div>

        <nav class="sa-nav" aria-label="Ayar bölümleri">
            <a href="#section-mode"     class="sa-nav-link" data-target="section-mode">
                <span class="sa-nav-icon" aria-hidden="true">⚡</span>
                <?php esc_html_e( 'Çalışma Modu', 'smart-assistant' ); ?>
            </a>
            <a href="#section-general"  class="sa-nav-link" data-target="section-general">
                <span class="sa-nav-icon" aria-hidden="true">🤖</span>
                <?php esc_html_e( 'AI Ayarları', 'smart-assistant' ); ?>
            </a>
            <a href="#section-content"  class="sa-nav-link" data-target="section-content">
                <span class="sa-nav-icon" aria-hidden="true">📚</span>
                <?php esc_html_e( 'İçerik Ayarları', 'smart-assistant' ); ?>
            </a>
            <a href="#section-identity" class="sa-nav-link" data-target="section-identity">
                <span class="sa-nav-icon" aria-hidden="true">🎭</span>
                <?php esc_html_e( 'AI Kimliği', 'smart-assistant' ); ?>
            </a>
            <a href="#section-advanced" class="sa-nav-link" data-target="section-advanced">
                <span class="sa-nav-icon" aria-hidden="true">⚙️</span>
                <?php esc_html_e( 'Gelişmiş', 'smart-assistant' ); ?>
            </a>
            <a href="#section-tools"    class="sa-nav-link" data-target="section-tools">
                <span class="sa-nav-icon" aria-hidden="true">🧪</span>
                <?php esc_html_e( 'Testler', 'smart-assistant' ); ?>
            </a>
            <a href="#section-test"     class="sa-nav-link" data-target="section-test">
                <span class="sa-nav-icon" aria-hidden="true">🔌</span>
                <?php esc_html_e( 'Test', 'smart-assistant' ); ?>
            </a>
            <a href="#section-on-test"  class="sa-nav-link" data-target="section-on-test">
                <span class="sa-nav-icon" aria-hidden="true">🛰️</span>
                <?php esc_html_e( 'ON Bağlantısı', 'smart-assistant' ); ?>
            </a>
            <a href="#section-chat"     class="sa-nav-link" data-target="section-chat">
                <span class="sa-nav-icon" aria-hidden="true">💬</span>
                <?php esc_html_e( 'Sohbet Testi', 'smart-assistant' ); ?>
            </a>
            <a href="#section-sync"     class="sa-nav-link" data-target="section-sync">
                <span class="sa-nav-icon" aria-hidden="true">🔄</span>
                <?php esc_html_e( 'Senkronizasyon', 'smart-assistant' ); ?>
            </a>
            <a href="#section-info"     class="sa-nav-link" data-target="section-info">
                <span class="sa-nav-icon" aria-hidden="true">ℹ️</span>
                <?php esc_html_e( 'Bilgi', 'smart-assistant' ); ?>
            </a>
            <a href="#section-repair"   class="sa-nav-link" data-target="section-repair">
                <span class="sa-nav-icon" aria-hidden="true">🔧</span>
                <?php esc_html_e( 'DB Onarım', 'smart-assistant' ); ?>
            </a>
        </nav>

        <div class="sa-status-card">
            <div class="sa-status-label"><?php esc_html_e( 'Aktif Mod', 'smart-assistant' ); ?></div>
            <div class="sa-status-pill sa-status-<?php echo 'open_notebook' === $mode ? 'mod2' : 'mod1'; ?>">
                <span class="sa-status-dot"></span>
                <?php echo 'open_notebook' === $mode ? esc_html__( 'Mod 2 — Open Notebook', 'smart-assistant' ) : esc_html__( 'Mod 1 — Basit', 'smart-assistant' ); ?>
            </div>
            <?php if ( 'open_notebook' === $mode && ! $has_on ) : ?>
                <div class="sa-status-warn">
                    <?php esc_html_e( '⚠️ ON URL/ID eksik, Mod 1\'e düşer.', 'smart-assistant' ); ?>
                </div>
            <?php elseif ( 'simple' === $mode && $has_on ) : ?>
                <div class="sa-status-hint">
                    <?php esc_html_e( 'Mod 2 ayarları hazır, sadece modu değiştir.', 'smart-assistant' ); ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <main class="sa-main">
        <header class="sa-hero">
            <div>
                <h1 class="sa-hero-title"><?php esc_html_e( 'Smart Assistant', 'smart-assistant' ); ?></h1>
                <p class="sa-hero-sub">
                    <?php esc_html_e( 'Sitenize yapay zekâ destekli arama ve sohbet asistanı ekleyin. Ayarları aşağıdan yönetin.', 'smart-assistant' ); ?>
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" class="sa-btn sa-btn-ghost">
                    <?php esc_html_e( 'Siteyi Gör', 'smart-assistant' ); ?>
                </a>
            </div>
        </header>

        <?php settings_errors( 'smart_assistant_options' ); ?>

        <form method="post" action="options.php" class="sa-form" id="smart-assistant-form">
            <?php settings_fields( 'smart_assistant_settings_group' ); ?>

            <section id="section-mode" class="sa-card" data-section="mode">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'Çalışma Modu', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Mod 1: WordPress araması + LLM. Mod 2: Open Notebook (semantik arama).', 'smart-assistant' ); ?></p>
                    </div>
                    <span class="sa-badge sa-badge-info"><?php esc_html_e( 'Başlangıç', 'smart-assistant' ); ?></span>
                </div>
                <div class="sa-card-body">
                    <?php smart_assistant_render_section( 'smart_assistant_mode' ); ?>
                </div>
            </section>

            <section id="section-general" class="sa-card" data-section="general">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'AI Ayarları', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Provider, API anahtarı, model ve davranış parametreleri.', 'smart-assistant' ); ?></p>
                    </div>
                </div>
                <div class="sa-card-body">
                    <?php smart_assistant_render_section( 'smart_assistant_general' ); ?>
                </div>
            </section>

            <section id="section-content" class="sa-card" data-section="content">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'İçerik Ayarları', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Hangi post type\'lardan arama yapılacak ve kaç sonuç getirilecek.', 'smart-assistant' ); ?></p>
                    </div>
                </div>
                <div class="sa-card-body">
                    <?php smart_assistant_render_section( 'smart_assistant_content' ); ?>
                </div>
            </section>

            <section id="section-identity" class="sa-card" data-section="identity">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'AI Kimliği', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Asistanın adı, tonu, selamlama mesajı ve few-shot örnekler.', 'smart-assistant' ); ?></p>
                    </div>
                    <span class="sa-badge sa-badge-info"><?php esc_html_e( 'Yeni', 'smart-assistant' ); ?></span>
                </div>
                <div class="sa-card-body">
                    <?php smart_assistant_render_section( 'smart_assistant_identity' ); ?>
                </div>
            </section>

            <section id="section-advanced" class="sa-card" data-section="advanced">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'Gelişmiş', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Open Notebook, rate limit, Abilities API.', 'smart-assistant' ); ?></p>
                    </div>
                </div>
                <div class="sa-card-body">
                    <?php smart_assistant_render_section( 'smart_assistant_advanced' ); ?>
                </div>
            </section>

            <?php
            $current_tools = smart_assistant_get_tools();
            $tool_idx      = 0;
            ?>
            <section id="section-tools" class="sa-card" data-section="tools">
                <div class="sa-card-head">
                    <div>
                        <h2 class="sa-card-title"><?php esc_html_e( 'Testler', 'smart-assistant' ); ?></h2>
                        <p class="sa-card-sub"><?php esc_html_e( 'Chatbot\'ta gösterilen hesaplayıcı araçları ekle, düzenle veya sil.', 'smart-assistant' ); ?></p>
                    </div>
                    <span class="sa-badge sa-badge-info"><?php echo esc_html( count( $current_tools ) ); ?></span>
                </div>
                <div class="sa-card-body">
                    <input type="hidden" name="smart_assistant_options[tools_submitted]" value="1" />
                    <div id="sa-tools-list">
                    <?php foreach ( $current_tools as $tool_key => $tool ) : ?>
                        <div class="sa-tool-row" data-index="<?php echo esc_attr( $tool_idx ); ?>">
                            <div class="sa-tool-row-summary">
                                <span class="sa-tool-row-icon sa-tool-preview-icon"><?php echo esc_html( $tool['icon'] ); ?></span>
                                <div class="sa-tool-row-info">
                                    <strong class="sa-tool-preview-label"><?php echo esc_html( $tool['label'] ); ?></strong>
                                    <code class="sa-tool-preview-key"><?php echo esc_html( $tool_key ); ?></code>
                                    <span class="sa-tool-preview-desc"><?php echo esc_html( $tool['description'] ); ?></span>
                                </div>
                                <div class="sa-tool-row-btns">
                                    <button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-toggle"><?php esc_html_e( 'Düzenle', 'smart-assistant' ); ?></button>
                                    <button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-delete" style="color:var(--sa-red-500)"><?php esc_html_e( 'Sil', 'smart-assistant' ); ?></button>
                                </div>
                            </div>
                            <div class="sa-tool-edit-fields" hidden>
                                <div class="sa-tool-fields-grid">
                                    <label><?php esc_html_e( 'Anahtar (key)', 'smart-assistant' ); ?><br>
                                        <input type="text" name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][key]"
                                               value="<?php echo esc_attr( $tool_key ); ?>" class="regular-text sa-tf-key"
                                               placeholder="<?php esc_attr_e( 'örn. bmi_hesapla', 'smart-assistant' ); ?>" />
                                    </label>
                                    <label><?php esc_html_e( 'İkon (emoji)', 'smart-assistant' ); ?><br>
                                        <input type="text" name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][icon]"
                                               value="<?php echo esc_attr( $tool['icon'] ); ?>" class="sa-tf-icon" />
                                    </label>
                                    <label><?php esc_html_e( 'Başlık', 'smart-assistant' ); ?><br>
                                        <input type="text" name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][label]"
                                               value="<?php echo esc_attr( $tool['label'] ); ?>" class="large-text sa-tf-label" />
                                    </label>
                                    <label><?php esc_html_e( 'Açıklama', 'smart-assistant' ); ?><br>
                                        <input type="text" name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][description]"
                                               value="<?php echo esc_attr( $tool['description'] ); ?>" class="large-text sa-tf-desc" />
                                    </label>
                                    <label style="grid-column:1/-1"><?php esc_html_e( 'Karşılama Mesajı', 'smart-assistant' ); ?><br>
                                        <textarea name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][welcome_msg]"
                                                  rows="2" class="large-text sa-tf-welcome"><?php echo esc_textarea( $tool['welcome_msg'] ); ?></textarea>
                                    </label>
                                    <label style="grid-column:1/-1"><?php esc_html_e( 'Sistem Prompt\'u', 'smart-assistant' ); ?><br>
                                        <textarea name="smart_assistant_options[tools][<?php echo esc_attr( $tool_idx ); ?>][system_prompt]"
                                                  rows="6" class="large-text sa-tf-prompt"><?php echo esc_textarea( $tool['system_prompt'] ); ?></textarea>
                                    </label>
                                </div>
                                <button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-tool-collapse" style="margin-top:10px">
                                    <?php esc_html_e( '↑ Kapat', 'smart-assistant' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php $tool_idx++; endforeach; ?>
                    </div>
                    <div class="sa-tools-actions">
                        <button type="button" id="sa-add-tool" class="sa-btn sa-btn-secondary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php esc_html_e( 'Yeni Test Ekle', 'smart-assistant' ); ?>
                        </button>
                        <button type="button" id="sa-reset-tools" class="sa-btn sa-btn-ghost">
                            <?php esc_html_e( 'Varsayılanlara Sıfırla', 'smart-assistant' ); ?>
                        </button>
                    </div>
                    <script>window.saToolsNextIdx = <?php echo intval( $tool_idx ); ?>;</script>
                </div>
            </section>

            <div class="sa-save-bar">
                <span class="sa-save-status" aria-live="polite"></span>
                <button type="submit" class="sa-btn sa-btn-primary" id="smart-assistant-save-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span class="sa-btn-text"><?php esc_html_e( 'Ayarları Kaydet', 'smart-assistant' ); ?></span>
                </button>
                <button type="button" class="sa-btn sa-btn-ghost" id="smart-assistant-save-rest" title="options.php yerine REST üzerinden doğrudan kaydeder. 'Kaydettim ama değişmedi' durumunda deneyin.">
                    <?php esc_html_e( 'REST ile Kaydet', 'smart-assistant' ); ?>
                </button>
            </div>
        </form>

        <section id="section-test" class="sa-card" data-section="test">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Test', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Seçili provider\'a bir ping atar; URL, auth ve model doğru mu?', 'smart-assistant' ); ?></p>
                </div>
            </div>
            <div class="sa-card-body">
                <button type="button" id="smart-assistant-test-btn" class="sa-btn sa-btn-secondary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    <span><?php esc_html_e( 'Provider\'ı Test Et', 'smart-assistant' ); ?></span>
                </button>
                <div id="smart-assistant-test-result" class="sa-test-result"></div>
                <pre id="smart-assistant-test-debug" class="sa-test-debug" hidden></pre>
            </div>
        </section>

        <section id="section-on-test" class="sa-card" data-section="on-test">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Open Notebook Bağlantısı', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Cloudflare Access token\'ları dahil ON API erişimini test eder; notebook listesini çeker.', 'smart-assistant' ); ?></p>
                </div>
            </div>
            <div class="sa-card-body">
                <?php if ( $has_on ) : ?>
                    <div class="sa-test-row">
                        <button type="button" id="smart-assistant-on-test-btn" class="sa-btn sa-btn-secondary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span><?php esc_html_e( 'ON Bağlantısını Test Et', 'smart-assistant' ); ?></span>
                        </button>
                        <span class="sa-test-status">
                            <?php
                            $has_cf = ! empty( $opts['on_cf_client_id'] ) && ! empty( $opts['on_cf_client_secret'] );
                            if ( $has_cf ) {
                                echo '<span class="sa-badge sa-badge-ok">🛡️ CF Access Service Token aktif</span>';
                            } else {
                                echo '<span class="sa-badge sa-badge-warn">⚠️ CF Access token yok (auth\'suz mod)</span>';
                            }
                            ?>
                        </span>
                    </div>
                    <div id="smart-assistant-on-test-result" class="sa-test-result"></div>
                    <pre id="smart-assistant-on-test-debug" class="sa-test-debug" hidden></pre>
                <?php else : ?>
                    <div class="sa-empty">
                        <div class="sa-empty-icon">🛰️</div>
                        <h3 class="sa-empty-title"><?php esc_html_e( 'Önce ON ayarlarını girin', 'smart-assistant' ); ?></h3>
                        <p class="sa-empty-text"><?php esc_html_e( '"Gelişmiş" bölümünden Open Notebook URL ve Notebook ID alanlarını doldurduğunuzda bu test aktif olur.', 'smart-assistant' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="section-chat" class="sa-card" data-section="chat">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Sohbet Testi', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Frontend public widget ile aynı kod yolunu dener; ayarları kaydetmeden önce davranışı görün.', 'smart-assistant' ); ?></p>
                </div>
                <span class="sa-badge sa-badge-info"><?php
                    echo 'open_notebook' === $mode
                        ? esc_html__( 'Mod 2 — ON', 'smart-assistant' )
                        : esc_html__( 'Mod 1 — WP + LLM', 'smart-assistant' );
                ?></span>
            </div>
            <div class="sa-card-body">
                <div id="sa-admin-chat" class="sa-chat">
                    <div class="sa-chat-messages" aria-live="polite"></div>
                    <div class="sa-chat-input-row">
                        <textarea class="sa-chat-input" rows="2" placeholder="<?php esc_attr_e( 'Bir soru sor…', 'smart-assistant' ); ?>"></textarea>
                        <div class="sa-chat-actions">
                            <button type="button" class="sa-btn sa-btn-secondary sa-chat-send"><?php esc_html_e( 'Gönder', 'smart-assistant' ); ?></button>
                            <button type="button" class="sa-btn sa-btn-ghost sa-chat-clear"><?php esc_html_e( 'Temizle', 'smart-assistant' ); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-sync" class="sa-card" data-section="sync">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Open Notebook Senkronizasyonu', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Tüm yazıları Open Notebook\'e source olarak gönderir (Mod 2 için).', 'smart-assistant' ); ?></p>
                </div>
            </div>
            <div class="sa-card-body">
                <?php if ( $has_on ) : ?>
                    <form method="post" class="sa-sync-form">
                        <?php wp_nonce_field( 'smart_assistant_action' ); ?>
                        <input type="hidden" name="smart_assistant_action" value="sync_all_posts" />
                        <button type="submit" class="sa-btn sa-btn-secondary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            <span><?php esc_html_e( 'Tüm Yazıları Şimdi Senkronize Et', 'smart-assistant' ); ?></span>
                        </button>
                    </form>
                    <?php if ( 'open_notebook' !== $mode ) : ?>
                        <div class="sa-alert sa-alert-info">
                            <?php esc_html_e( 'Mod 1 kullanıyorsun. Sync yine de çalışır; ON\'e yazılır. Mod 2 için yukarıdan "Çalışma Modu"nu değiştir.', 'smart-assistant' ); ?>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="sa-empty">
                        <div class="sa-empty-icon">🔌</div>
                        <h3 class="sa-empty-title"><?php esc_html_e( 'Henüz bağlanmadı', 'smart-assistant' ); ?></h3>
                        <p class="sa-empty-text"><?php esc_html_e( 'Open Notebook URL ve Notebook ID girildiğinde senkronizasyon butonu aktif olur.', 'smart-assistant' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="section-info" class="sa-card" data-section="info">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Bilgi', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Frontend kullanımı ve Abilities API.', 'smart-assistant' ); ?></p>
                </div>
            </div>
            <div class="sa-card-body sa-info-grid">
                <div class="sa-info-block">
                    <h4><?php esc_html_e( 'Frontend Kullanımı', 'smart-assistant' ); ?></h4>
                    <ul>
                        <li><?php esc_html_e( 'Tüm sayfalarda sağ alt köşede asistan balonu görünür.', 'smart-assistant' ); ?></li>
                        <li><?php esc_html_e( 'Yazı/sayfa detayında scroll ile takip eden "Özetle" butonu belirir.', 'smart-assistant' ); ?></li>
                        <li><?php esc_html_e( 'Mini sohbetten "Genişlet" diyerek sağdaki sütuna geçebilirsiniz.', 'smart-assistant' ); ?></li>
                        <li><?php esc_html_e( 'Sohbetler hiçbir yerde saklanmaz; cache temizlendiğinde veya "Sil" dediğinizde sıfırlanır.', 'smart-assistant' ); ?></li>
                    </ul>
                </div>
                <div class="sa-info-block">
                    <h4><?php esc_html_e( 'Abilities API (WordPress 7.0)', 'smart-assistant' ); ?></h4>
                    <p><?php esc_html_e( 'Aktifse, dış AI agent\'lar (Claude Desktop vs.) sitemize bağlanıp şu yetenekleri kullanabilir:', 'smart-assistant' ); ?></p>
                    <ul class="sa-code-list">
                        <li><code>smart-assistant/search_content</code></li>
                        <li><code>smart-assistant/get_post</code></li>
                    </ul>
                    <p class="sa-muted"><?php esc_html_e( 'Yetenekler yalnızca giriş yapmış kullanıcılar tarafından kullanılabilir.', 'smart-assistant' ); ?></p>
                </div>
            </div>
        </section>

        <section id="section-repair" class="sa-card" data-section="repair">
            <div class="sa-card-head">
                <div>
                    <h2 class="sa-card-title"><?php esc_html_e( 'Veritabanı Onarım', 'smart-assistant' ); ?></h2>
                    <p class="sa-card-sub"><?php esc_html_e( 'Ayarlar kaydedilmiyorsa, veritabanındaki option satırını tanılayın ve onarın.', 'smart-assistant' ); ?></p>
                </div>
                <span class="sa-badge sa-badge-warn">🔧</span>
            </div>
            <div class="sa-card-body">
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                    <button type="button" id="sa-repair-diagnose" class="sa-btn sa-btn-secondary">
                        🔍 <?php esc_html_e( 'Tanıla (Diagnose)', 'smart-assistant' ); ?>
                    </button>
                    <button type="button" id="sa-repair-reset" class="sa-btn sa-btn-ghost" style="color:var(--sa-red-500, #ef4444); border-color:var(--sa-red-500, #ef4444);">
                        🗑️ <?php esc_html_e( 'Sıfırla & Yeniden Oluştur', 'smart-assistant' ); ?>
                    </button>
                    <button type="button" id="sa-repair-force-save" class="sa-btn sa-btn-secondary">
                        💾 <?php esc_html_e( 'Force Save (DB Direkt)', 'smart-assistant' ); ?>
                    </button>
                </div>
                <p class="description" style="margin-bottom:12px;">
                    <?php esc_html_e( '• Tanıla: Option\'ın DB\'de var olup olmadığını, serializasyon bütünlüğünü ve yazma yeteneğini kontrol eder.', 'smart-assistant' ); ?><br>
                    <?php esc_html_e( '• Sıfırla: Mevcut option\'ı siler ve varsayılan değerlerle sıfırdan oluşturur. API anahtarınız silinir!', 'smart-assistant' ); ?><br>
                    <?php esc_html_e( '• Force Save: Formdaki mevcut değerleri WP\'yi atlayarak doğrudan $wpdb ile veritabanına yazar.', 'smart-assistant' ); ?>
                </p>
                <pre id="sa-repair-result" style="background:#1e293b; color:#e2e8f0; padding:14px; border-radius:8px; font-size:12px; max-height:400px; overflow:auto; display:none; white-space:pre-wrap;"></pre>
            </div>
        </section>

        <script>
        (function($){
            var restUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'smart-assistant/v1' ) ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
            var $result = $('#sa-repair-result');

            function repairCall(action, extraData) {
                $result.show().text('⏳ İşlem devam ediyor…');
                var payload = extraData || {};
                payload.action = action;
                $.ajax({
                    url: restUrl + '/repair-options',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    headers: { 'X-WP-Nonce': nonce },
                    success: function(data) {
                        $result.text(JSON.stringify(data, null, 2));
                        if (data.ok) {
                            $result.css('border-left', '4px solid #22c55e');
                        } else {
                            $result.css('border-left', '4px solid #ef4444');
                        }
                    },
                    error: function(xhr) {
                        var msg = 'HTTP ' + xhr.status;
                        try { msg += ': ' + JSON.parse(xhr.responseText).message; } catch(e){}
                        $result.text('❌ ' + msg).css('border-left', '4px solid #ef4444');
                    }
                });
            }

            $('#sa-repair-diagnose').on('click', function(){ repairCall('diagnose'); });

            $('#sa-repair-reset').on('click', function(){
                if (!confirm('DİKKAT: Tüm eklenti ayarları (API anahtarı dahil) silinip varsayılanlara dönecek. Devam?')) return;
                repairCall('reset');
            });

            $('#sa-repair-force-save').on('click', function(){
                // Mevcut formdaki verileri topla.
                var formData = $('#smart-assistant-form').serializeArray();
                var payload = {};
                formData.forEach(function(f){
                    var m = f.name.match(/^smart_assistant_options\[(.+?)\](.*)$/);
                    if (!m) return;
                    var topKey = m[1], rest = m[2];
                    if (!rest) {
                        payload[topKey] = f.value;
                        return;
                    }
                    if (!payload[topKey] || typeof payload[topKey] !== 'object') payload[topKey] = {};
                    var path = [];
                    rest.replace(/\[([^\]]*)\]/g, function(_, k){ path.push(k); });
                    var cur = payload[topKey];
                    for (var i = 0; i < path.length; i++) {
                        var k = path[i];
                        if (i === path.length - 1) { cur[k] = f.value; }
                        else { if (!cur[k] || typeof cur[k] !== 'object') cur[k] = {}; cur = cur[k]; }
                    }
                });
                repairCall('force_save', payload);
            });
        })(jQuery);
        </script>
    </main>
</div>

<?php
// === DEBUG PANEL — sadece WP_DEBUG modunda görünür ===
// Save sonrası DB'de ne yazıldığını canlı kontrol için.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) :
    $raw_dbg = get_option( 'smart_assistant_options', [] );
    $cooked_dbg = smart_assistant_get_options();
    $mask_keys  = [ 'api_key', 'group_id', 'on_cf_client_id', 'on_cf_client_secret' ];
    $mask_fn = function ( $v ) {
        if ( ! is_string( $v ) || '' === $v ) return $v;
        $len = strlen( $v );
        return $len <= 8 ? str_repeat( '•', $len ) : substr( $v, 0, 4 ) . str_repeat( '•', 8 ) . substr( $v, -4 ) . ' (len=' . $len . ')';
    };
    $save_in_dbg  = get_option( 'smart_assistant_debug_save_in',  null );
    $save_out_dbg = get_option( 'smart_assistant_debug_save_out', null );

    $snapshot = [
        'raw_db_keys'   => is_array( $raw_dbg ) ? array_keys( $raw_dbg ) : null,
        'cooked_keys'   => is_array( $cooked_dbg ) ? array_keys( $cooked_dbg ) : null,
        'mode'          => $cooked_dbg['mode'] ?? 'N/A',
        'ai_tone'       => $cooked_dbg['ai_tone'] ?? 'N/A',
        'tools_count'   => isset( $cooked_dbg['tools'] ) && is_array( $cooked_dbg['tools'] ) ? count( $cooked_dbg['tools'] ) : 0,
        'tool_keys'     => isset( $cooked_dbg['tools'] ) && is_array( $cooked_dbg['tools'] )
            ? array_values( array_filter( array_map( fn( $t ) => is_array( $t ) ? ( $t['key'] ?? '' ) : '', $cooked_dbg['tools'] ) ) )
            : [],
        'on_url'        => $cooked_dbg['open_notebook_url'] ?? '',
        'cf_id_len'     => strlen( $cooked_dbg['on_cf_client_id']     ?? '' ),
        'cf_secret_len' => strlen( $cooked_dbg['on_cf_client_secret'] ?? '' ),
        'masked'        => array_intersect_key( $cooked_dbg, array_flip( $mask_keys ) ),
        'save_in'       => $save_in_dbg,   // sanitize() GİRİŞ snapshot
        'save_out'      => $save_out_dbg,  // sanitize() ÇIKIŞ snapshot
    ];
    foreach ( $mask_keys as $k ) {
        if ( isset( $snapshot['masked'][ $k ] ) ) {
            $snapshot['masked'][ $k ] = $mask_fn( $snapshot['masked'][ $k ] );
        }
    }
    ?>
    <div style="position:fixed;bottom:0;left:0;right:0;max-height:40vh;overflow:auto;background:#0f172a;color:#e2e8f0;font-family:ui-monospace,Menlo,monospace;font-size:11px;padding:10px 14px;border-top:2px solid #6366f1;z-index:99999;box-shadow:0 -4px 12px rgba(0,0,0,0.4);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <strong style="color:#fbbf24;">⚙️ SMART ASSISTANT DEBUG SNAPSHOT (WP_DEBUG açık)</strong>
            <span style="color:#94a3b8;">Bu panel sadece hata ayıklama için — production'da WP_DEBUG kapatınca kaybolur.</span>
        </div>
        <pre style="margin:0;white-space:pre-wrap;"><?php echo esc_html( print_r( $snapshot, true ) ); ?></pre>
    </div>
    <?php
endif;
