<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ana plugin sınıfı — Singleton.
 */
final class Plugin {

    /** @var Plugin */
    private static $instance = null;

    /** @var Loader */
    public $loader;

    /** @var Settings */
    public $settings;

    /** @var AIClient */
    public $ai_client;

    /** @var Search */
    public $search;

    /** @var OpenNotebook */
    public $open_notebook;

    /** @var RestController */
    public $rest;

    /** @var Abilities */
    public $abilities;

    /** @var Frontend */
    public $frontend;

    /** @var Admin */
    public $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->loader       = new Loader();
        $this->settings     = new Settings();
        $this->ai_client    = new AIClient();
        $this->search       = new Search();
        $this->open_notebook = new OpenNotebook();
        $this->rest         = new RestController();
        $this->abilities    = new Abilities();
        $this->frontend     = new Frontend();
        if ( is_admin() ) {
            $this->admin    = new Admin();
        }
    }

    public function boot() {
        load_plugin_textdomain( 'smart-assistant', false, dirname( SMART_ASSISTANT_BASENAME ) . '/languages' );

        $this->loader->register_hooks();
        $this->settings->register_hooks();
        $this->rest->register_hooks();
        $this->abilities->register_hooks();
        $this->frontend->register_hooks();
        if ( is_admin() && $this->admin ) {
            $this->admin->register_hooks();
        }
    }
}