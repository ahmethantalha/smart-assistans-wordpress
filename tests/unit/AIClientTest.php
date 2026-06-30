<?php
/**
 * AIClient birim testleri.
 *
 * WordPress API çağrıları Brain\Monkey ile taklit edilir; gerçek HTTP isteği atılmaz.
 * Test edilen davranışlar: thinking temizleme, bozuk link temizleme,
 * Gemini turn normalizasyonu.
 */

namespace SmartAssistant\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SmartAssistant\AIClient;

class AIClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // strip_thinking / strip_broken_links / gemini_normalize_turns metodları
        // WP fonksiyonu çağırmaz; Patchwork stub'ına gerek yok.
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── strip_thinking ────────────────────────────────────────────────────────

    public function test_strip_thinking_removes_thinking_tags(): void {
        $client = new AIClient();
        $result = $client->strip_thinking( 'Merhaba <thinking>Şimdi düşünüyorum...</thinking> dünya.' );
        $this->assertSame( 'Merhaba  dünya.', $result );
    }

    public function test_strip_thinking_removes_think_tags(): void {
        $client = new AIClient();
        $result = $client->strip_thinking( '<think>İç muhakeme.</think>Cevap: 42.' );
        $this->assertSame( 'Cevap: 42.', $result );
    }

    public function test_strip_thinking_removes_markdown_code_block(): void {
        $client = new AIClient();
        $result = $client->strip_thinking( "```thinking\niç muhakeme\n```\nAsıl cevap." );
        $this->assertSame( 'Asıl cevap.', $result );
    }

    public function test_strip_thinking_leaves_normal_text_intact(): void {
        $client = new AIClient();
        $text   = 'Bu makale hakkında bilgi verebilir misiniz?';
        $this->assertSame( $text, $client->strip_thinking( $text ) );
    }

    public function test_strip_thinking_leaves_empty_string_intact(): void {
        $client = new AIClient();
        $this->assertSame( '', $client->strip_thinking( '' ) );
    }

    // ── strip_broken_links ────────────────────────────────────────────────────

    public function test_strip_broken_links_removes_attribute_leakage(): void {
        $client = new AIClient();
        $input  = 'Okuyun: https://site.com/yazi/" target="_blank" rel="noopener noreferrer">Yazı Başlığı ve devamı.';
        $result = $client->strip_broken_links( $input );
        $this->assertStringNotContainsString( 'target="_blank"', $result );
        $this->assertStringNotContainsString( 'rel="noopener noreferrer"', $result );
    }

    public function test_strip_broken_links_removes_orphan_target_attribute(): void {
        $client = new AIClient();
        $input  = 'Detay target="_blank" rel="noopener noreferrer">Başlık metni burada.';
        $result = $client->strip_broken_links( $input );
        $this->assertStringNotContainsString( 'target=', $result );
    }

    public function test_strip_broken_links_removes_unclosed_anchor_tag(): void {
        $client = new AIClient();
        $input  = 'Detaylar için <a href="https://site.com/foo"';
        $result = $client->strip_broken_links( $input );
        $this->assertStringNotContainsString( '<a ', $result );
    }

    public function test_strip_broken_links_leaves_clean_markdown_unchanged(): void {
        $client = new AIClient();
        $text   = 'Detaylar için [Yazı Başlığı](https://site.com/yazi/) okuyun.';
        $this->assertSame( $text, $client->strip_broken_links( $text ) );
    }

    public function test_strip_broken_links_leaves_plain_url_unchanged(): void {
        $client = new AIClient();
        $text   = 'Kaynak: https://site.com/makale/';
        $this->assertSame( $text, $client->strip_broken_links( $text ) );
    }

    // ── gemini_normalize_turns (private, Reflection ile) ─────────────────────

    private function callGeminiNormalize( array $contents ): array {
        $client     = new AIClient();
        $reflection = new \ReflectionMethod( AIClient::class, 'gemini_normalize_turns' );
        $reflection->setAccessible( true );
        return $reflection->invoke( $client, $contents );
    }

    public function test_gemini_normalize_merges_consecutive_user_turns(): void {
        $input = [
            [ 'role' => 'user',  'parts' => [ [ 'text' => 'Birinci soru.' ] ] ],
            [ 'role' => 'user',  'parts' => [ [ 'text' => 'İkinci soru.' ] ] ],
            [ 'role' => 'model', 'parts' => [ [ 'text' => 'Cevap.' ] ] ],
        ];
        $result = $this->callGeminiNormalize( $input );
        $this->assertCount( 2, $result );
        $this->assertCount( 2, $result[0]['parts'] ); // İki user mesajı birleşti.
    }

    public function test_gemini_normalize_prepends_user_turn_if_starts_with_model(): void {
        $input = [
            [ 'role' => 'model', 'parts' => [ [ 'text' => 'Beklenmedik model turn.' ] ] ],
            [ 'role' => 'user',  'parts' => [ [ 'text' => 'Kullanıcı sorusu.' ] ] ],
        ];
        $result = $this->callGeminiNormalize( $input );
        $this->assertSame( 'user', $result[0]['role'] );
    }

    public function test_gemini_normalize_leaves_alternating_turns_intact(): void {
        $input = [
            [ 'role' => 'user',  'parts' => [ [ 'text' => 'Soru.' ] ] ],
            [ 'role' => 'model', 'parts' => [ [ 'text' => 'Cevap.' ] ] ],
            [ 'role' => 'user',  'parts' => [ [ 'text' => 'Devam sorusu.' ] ] ],
        ];
        $result = $this->callGeminiNormalize( $input );
        $this->assertCount( 3, $result );
    }

    public function test_gemini_normalize_handles_empty_input(): void {
        $result = $this->callGeminiNormalize( [] );
        $this->assertSame( [], $result );
    }
}
