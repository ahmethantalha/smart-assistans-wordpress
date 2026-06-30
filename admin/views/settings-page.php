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
            <a href="#section-test"     class="sa-nav-link" data-target="section-test">
                <span class="sa-nav-icon" aria-hidden="true">🔌</span>
                <?php esc_html_e( 'Test', 'smart-assistant' ); ?>
            </a>
            <a href="#section-sync"     class="sa-nav-link" data-target="section-sync">
                <span class="sa-nav-icon" aria-hidden="true">🔄</span>
                <?php esc_html_e( 'Senkronizasyon', 'smart-assistant' ); ?>
            </a>
            <a href="#section-info"     class="sa-nav-link" data-target="section-info">
                <span class="sa-nav-icon" aria-hidden="true">ℹ️</span>
                <?php esc_html_e( 'Bilgi', 'smart-assistant' ); ?>
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

            <div class="sa-save-bar">
                <span class="sa-save-status" aria-live="polite"></span>
                <button type="submit" class="sa-btn sa-btn-primary" id="smart-assistant-save-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span class="sa-btn-text"><?php esc_html_e( 'Ayarları Kaydet', 'smart-assistant' ); ?></span>
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
    </main>
</div>
