<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook loader — şimdilik sade. İleride conditional hook ihtiyacı olursa burada toplanır.
 */
class Loader {
    public function register_hooks() {
        // Şimdilik merkezi loader'a ihtiyaç yok; her modül kendi hook'larını kayıt eder.
    }
}