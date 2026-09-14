<?php
/**
 * Plugin Name: Synka Auto SEO Content Generator
 * Plugin URI: https://clickku.id
 * Description: Plugin WordPress cerdas untuk otomatisasi pembuatan konten artikel SEO dari Google Spreadsheet menggunakan AI (Gemini / OpenAI), lengkap dengan gambar thumbnail, optimasi RankMath/Yoast, dan penjadwalan otomatis.
 * Version: 1.0.0
 * Author: Synka & Clickku
 * Author URI: https://clickku.id
 * License: GPLv2 or later
 * Text Domain: synka-auto-seo
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('SYNKA_AUTO_SEO_VERSION', '1.1.0');
define('SYNKA_AUTO_SEO_PATH', plugin_dir_path(__FILE__));
define('SYNKA_AUTO_SEO_URL', plugin_dir_url(__FILE__));

// Require Core Classes
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-sheets-connector.php';
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-ai-generator.php';
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-image-handler.php';
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-post-creator.php';
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-scheduler.php';
require_once SYNKA_AUTO_SEO_PATH . 'includes/class-admin-settings.php';

/**
 * Main Plugin Class
 */
class Synka_Auto_SEO {

    private static $instance = null;
    public $sheets;
    public $ai;
    public $image;
    public $post_creator;
    public $scheduler;
    public $admin;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->sheets = new Synka_Auto_SEO_Sheets_Connector();
        $this->ai = new Synka_Auto_SEO_AI_Generator();
        $this->image = new Synka_Auto_SEO_Image_Handler();
        $this->post_creator = new Synka_Auto_SEO_Post_Creator($this->image);
        $this->scheduler = new Synka_Auto_SEO_Scheduler($this->sheets, $this->ai, $this->post_creator);
        $this->admin = new Synka_Auto_SEO_Admin_Settings($this->sheets, $this->ai, $this->scheduler);

        // Activation & Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        // Schedule Cron on activation
        $this->scheduler->register_cron();
        
        // Initialize default options
        if (!get_option('synka_auto_seo_settings')) {
            update_option('synka_auto_seo_settings', [
                'ai_provider' => 'gemini',
                'gemini_api_key' => '',
                'openai_api_key' => '',
                'openai_model' => 'gpt-4o-mini',
                'sheet_mode' => 'apps_script',
                'sheet_url' => '',
                'cron_interval' => 'hourly',
                'post_status' => 'publish', // publish, draft, future
                'default_author' => 1,
                'default_category' => 1,
                'enable_image' => 1,
                'unsplash_api_key' => '',
                'article_length' => '1200-1800',
                'article_language' => 'id',
            ]);
        }
    }

    public function deactivate() {
        $this->scheduler->clear_cron();
    }
}

// Initialize Plugin
function synka_auto_seo_init() {
    return Synka_Auto_SEO::get_instance();
}
synka_auto_seo_init();
