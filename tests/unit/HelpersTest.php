<?php
/**
 * helpers.php fonksiyonları için birim testleri.
 */

namespace SmartAssistant\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── smart_assistant_rate_limit_key ────────────────────────────────────────

    public function test_rate_limit_key_is_deterministic(): void {
        $key1 = smart_assistant_rate_limit_key( '192.168.1.1' );
        $key2 = smart_assistant_rate_limit_key( '192.168.1.1' );
        $this->assertSame( $key1, $key2 );
    }

    public function test_rate_limit_key_differs_per_ip(): void {
        $key1 = smart_assistant_rate_limit_key( '192.168.1.1' );
        $key2 = smart_assistant_rate_limit_key( '10.0.0.1' );
        $this->assertNotSame( $key1, $key2 );
    }

    public function test_rate_limit_key_has_expected_prefix(): void {
        $key = smart_assistant_rate_limit_key( '127.0.0.1' );
        $this->assertStringStartsWith( 'smart_assistant_rl_', $key );
    }

    // ── smart_assistant_get_options defaults ──────────────────────────────────

    public function test_get_options_returns_defaults_when_option_empty(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'post_type_exists' )->justReturn( false );

        $opts = smart_assistant_get_options();

        $this->assertSame( 'simple',              $opts['mode'] );
        $this->assertSame( 'MiniMax',             $opts['provider'] );
        $this->assertSame( 'https://api.minimax.io/v1', $opts['api_base_url'] );
        $this->assertSame( 'MiniMax-M3',          $opts['model'] );
        $this->assertSame( 20,                    $opts['rate_limit_per_min'] );
        $this->assertContains( 'post',            $opts['post_types'] );
    }

    public function test_get_options_merges_saved_with_defaults(): void {
        Functions\when( 'get_option' )->justReturn( [ 'api_key' => 'abc123', 'model' => 'gpt-4o' ] );
        Functions\when( 'post_type_exists' )->justReturn( false );

        $opts = smart_assistant_get_options();

        $this->assertSame( 'abc123',   $opts['api_key'] );
        $this->assertSame( 'gpt-4o',   $opts['model'] );
        $this->assertSame( 'simple',   $opts['mode'] );  // default korundu
    }

    public function test_get_options_migration_fixes_old_url(): void {
        Functions\when( 'get_option' )->justReturn( [
            'api_base_url' => 'https://api.MiniMax.chat/v1',
        ] );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'post_type_exists' )->justReturn( false );

        $opts = smart_assistant_get_options();

        $this->assertSame( 'https://api.minimax.io/v1', $opts['api_base_url'] );
    }

    // ── smart_assistant_get_provider_presets ──────────────────────────────────

    public function test_all_providers_have_required_keys(): void {
        $presets = smart_assistant_get_provider_presets();

        foreach ( $presets as $key => $preset ) {
            $this->assertArrayHasKey( 'label',    $preset, "Provider '$key' 'label' eksik" );
            $this->assertArrayHasKey( 'base_url', $preset, "Provider '$key' 'base_url' eksik" );
            $this->assertArrayHasKey( 'models',   $preset, "Provider '$key' 'models' eksik" );
            $this->assertArrayHasKey( 'auth',     $preset, "Provider '$key' 'auth' eksik" );
            $this->assertNotEmpty( $preset['models'], "Provider '$key' model listesi boş" );
        }
    }

    public function test_anthropic_base_url_is_correct(): void {
        $presets = smart_assistant_get_provider_presets();
        $this->assertSame( 'https://api.anthropic.com', $presets['anthropic']['base_url'] );
    }

    public function test_gemini_base_url_is_correct(): void {
        $presets = smart_assistant_get_provider_presets();
        $this->assertSame( 'https://generativelanguage.googleapis.com', $presets['gemini']['base_url'] );
    }

    public function test_minimax_base_url_is_correct(): void {
        $presets = smart_assistant_get_provider_presets();
        $this->assertSame( 'https://api.minimax.io/v1', $presets['MiniMax']['base_url'] );
    }
}
