<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deaktivasyon: opsiyonel temizlik.
 * Veri tabanı tutmadığımız için silme yok; sadece transient'leri temizleriz.
 */
class Deactivator {

    public static function deactivate() {
        delete_transient( 'smart_assistant_activated_notice' );
        // Rate limit transient'leri süresi dolunca zaten temizlenir.
    }
}