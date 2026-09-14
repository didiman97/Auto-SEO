<?php
/**
 * Synka Auto SEO - Admin Settings Page & AJAX Handlers
 * Provides modern WP-Admin Dashboard UI for managing AI, Google Sheets, Cron, and Logs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_Admin_Settings {

    private $sheets;
    private $ai;
    private $scheduler;

    public function __construct($sheets, $ai, $scheduler) {
        $this->sheets = $sheets;
        $this->ai = $ai;
        $this->scheduler = $scheduler;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX Hooks
        add_action('wp_ajax_synka_auto_seo_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_synka_auto_seo_get_pending_list', [$this, 'ajax_get_pending_list']);
        add_action('wp_ajax_synka_auto_seo_process_single_row', [$this, 'ajax_process_single_row']);
        add_action('wp_ajax_synka_auto_seo_manual_run', [$this, 'ajax_manual_run']);
        add_action('wp_ajax_synka_auto_seo_clear_logs', [$this, 'ajax_clear_logs']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Synka Auto SEO', 'synka-auto-seo'),
            __('Auto SEO Content', 'synka-auto-seo'),
            'manage_options',
            'synka-auto-seo',
            [$this, 'render_settings_page'],
            'dashicons-superhero',
            30
        );
    }

    public function register_settings() {
        register_setting('synka_auto_seo_group', 'synka_auto_seo_settings', [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        $sanitized['ai_provider'] = in_array($input['ai_provider'], ['gemini', 'openai']) ? $input['ai_provider'] : 'gemini';
        $sanitized['gemini_api_key'] = sanitize_text_field($input['gemini_api_key']);
        $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
        $sanitized['openai_model'] = sanitize_text_field($input['openai_model']);
        $sanitized['sheet_mode'] = in_array($input['sheet_mode'], ['apps_script', 'csv']) ? $input['sheet_mode'] : 'apps_script';
        $sanitized['sheet_url'] = esc_url_raw(trim($input['sheet_url']));
        $sanitized['main_site_url'] = esc_url_raw(trim($input['main_site_url']));
        $sanitized['cron_interval'] = sanitize_text_field($input['cron_interval']);
        $sanitized['cron_batch_limit'] = in_array($input['cron_batch_limit'], ['1', '3', '5', '10', 'all']) ? $input['cron_batch_limit'] : '5';
        $sanitized['post_status'] = in_array($input['post_status'], ['publish', 'draft', 'future']) ? $input['post_status'] : 'publish';
        $sanitized['default_author'] = (int)$input['default_author'];
        $sanitized['default_category'] = (int)$input['default_category'];
        $sanitized['enable_image'] = isset($input['enable_image']) ? 1 : 0;
        $sanitized['unsplash_api_key'] = sanitize_text_field($input['unsplash_api_key']);
        $sanitized['article_length'] = sanitize_text_field($input['article_length']);
        $sanitized['article_language'] = sanitize_text_field($input['article_language']);

        // Reschedule cron if interval changed
        $this->scheduler->clear_cron();
        $this->scheduler->register_cron();

        return $sanitized;
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_synka-auto-seo') {
            return;
        }

        $css_ver = file_exists(SYNKA_AUTO_SEO_PATH . 'assets/css/admin.css') ? filemtime(SYNKA_AUTO_SEO_PATH . 'assets/css/admin.css') : SYNKA_AUTO_SEO_VERSION;
        $js_ver = file_exists(SYNKA_AUTO_SEO_PATH . 'assets/js/admin.js') ? filemtime(SYNKA_AUTO_SEO_PATH . 'assets/js/admin.js') : SYNKA_AUTO_SEO_VERSION;

        wp_enqueue_style(
            'synka-auto-seo-admin-css',
            SYNKA_AUTO_SEO_URL . 'assets/css/admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'synka-auto-seo-admin-js',
            SYNKA_AUTO_SEO_URL . 'assets/js/admin.js',
            ['jquery'],
            $js_ver,
            true
        );

        wp_localize_script('synka-auto-seo-admin-js', 'synkaAutoSeo', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('synka_auto_seo_nonce'),
        ]);
    }

    /**
     * Render Admin Settings Page
     */
    public function render_settings_page() {
        $settings = get_option('synka_auto_seo_settings', []);
        $logs = get_option('synka_auto_seo_logs', []);
        $authors = get_users(['role__in' => ['administrator', 'editor', 'author']]);
        $categories = get_categories(['hide_empty' => false]);
        $next_cron = wp_next_scheduled(Synka_Auto_SEO_Scheduler::CRON_HOOK);
        ?>
        <div class="wrap synka-seo-wrap">
            <div class="synka-header">
                <div class="synka-header-left">
                    <div class="synka-logo-badge"><span class="dashicons dashicons-superhero"></span> Synka AI</div>
                    <h1>Auto SEO Content Generator</h1>
                    <p class="synka-tagline">Otomasi penulisan artikel SEO terjadwal dari Google Spreadsheet dengan AI (Gemini / OpenAI).</p>
                </div>
                <div class="synka-header-actions">
                    <button type="button" id="btn-test-connection" class="button button-secondary">
                        <span class="dashicons dashicons-admin-plugins"></span> Test Koneksi & Cek Ready
                    </button>
                    <button type="button" id="btn-manual-run" class="button button-secondary">
                        <span class="dashicons dashicons-controls-play"></span> Generate 1 Artikel
                    </button>
                    <button type="button" id="btn-generate-all" class="button button-primary" style="background:#10b981; border-color:#059669;">
                        <span class="dashicons dashicons-cloud-upload"></span> <strong>Generate Semua Artikel Ready</strong>
                    </button>
                </div>
            </div>

            <!-- PROGRESS BOX FOR BATCH GENERATION -->
            <div id="synka-progress-box" class="synka-progress-box" style="display:none;">
                <div class="synka-progress-header">
                    <span id="synka-progress-title">⚡ Memproses Artikel Ready...</span>
                    <span id="synka-progress-percent">0%</span>
                </div>
                <div class="synka-progress-bar-bg">
                    <div id="synka-progress-bar-fill" class="synka-progress-bar-fill"></div>
                </div>
                <div id="synka-progress-log" class="synka-progress-log"></div>
            </div>

            <div id="synka-alert-box" class="synka-alert" style="display:none;"></div>

            <div class="synka-grid">
                <!-- LEFT: SETTINGS FORM -->
                <div class="synka-col-settings">
                    <form method="post" action="options.php">
                        <?php settings_fields('synka_auto_seo_group'); ?>

                        <!-- 1. Google Sheets Integration -->
                        <div class="synka-card">
                            <div class="synka-card-header">
                                <h2><span class="dashicons dashicons-media-spreadsheet"></span> 1. Integrasi Google Spreadsheet</h2>
                            </div>
                            <div class="synka-card-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="sheet_mode">Metode Koneksi</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[sheet_mode]" id="sheet_mode" class="regular-text">
                                                <option value="apps_script" <?php selected(isset($settings['sheet_mode']) ? $settings['sheet_mode'] : '', 'apps_script'); ?>>Google Apps Script Webhook (Rekomendasi - Two Way Sync)</option>
                                                <option value="csv" <?php selected(isset($settings['sheet_mode']) ? $settings['sheet_mode'] : '', 'csv'); ?>>Published Google Sheet (CSV URL - Read Only)</option>
                                            </select>
                                            <p class="description">Gunakan Apps Script Webhook agar status artikel di Spreadsheet otomatis terupdate menjadi "Published" beserta URL artikelnya.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="sheet_url">URL Webhook / CSV</label></th>
                                        <td>
                                            <input type="url" name="synka_auto_seo_settings[sheet_url]" id="sheet_url" value="<?php echo esc_attr(isset($settings['sheet_url']) ? $settings['sheet_url'] : ''); ?>" class="large-text" placeholder="https://script.google.com/macros/s/.../exec">
                                            <p class="description">
                                                <a href="#apps-script-guide" id="btn-show-script-modal" class="synka-link-guide">📖 Lihat Template Kode Google Apps Script & Struktur Kolom</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- 2. AI Engine Config -->
                        <div class="synka-card">
                            <div class="synka-card-header">
                                <h2><span class="dashicons dashicons-admin-generic"></span> 2. Konfigurasi AI Engine</h2>
                            </div>
                            <div class="synka-card-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="ai_provider">Provider AI Utama</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[ai_provider]" id="ai_provider" class="regular-text">
                                                <option value="gemini" <?php selected(isset($settings['ai_provider']) ? $settings['ai_provider'] : '', 'gemini'); ?>>Google Gemini (Sangat Cepat & Hemat Kuota)</option>
                                                <option value="openai" <?php selected(isset($settings['ai_provider']) ? $settings['ai_provider'] : '', 'openai'); ?>>OpenAI (GPT-4o / GPT-4o-mini)</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr class="row-gemini">
                                        <th scope="row"><label for="gemini_api_key">Google Gemini API Key</label></th>
                                        <td>
                                            <input type="password" name="synka_auto_seo_settings[gemini_api_key]" id="gemini_api_key" value="<?php echo esc_attr(isset($settings['gemini_api_key']) ? $settings['gemini_api_key'] : ''); ?>" class="large-text" placeholder="AIzaSy...">
                                            <p class="description">Dapatkan API Key gratis di <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.</p>
                                        </td>
                                    </tr>
                                    <tr class="row-openai">
                                        <th scope="row"><label for="openai_api_key">OpenAI API Key</label></th>
                                        <td>
                                            <input type="password" name="synka_auto_seo_settings[openai_api_key]" id="openai_api_key" value="<?php echo esc_attr(isset($settings['openai_api_key']) ? $settings['openai_api_key'] : ''); ?>" class="large-text" placeholder="sk-...">
                                        </td>
                                    </tr>
                                    <tr class="row-openai">
                                        <th scope="row"><label for="openai_model">Model OpenAI</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[openai_model]" id="openai_model" class="regular-text">
                                                <option value="gpt-4o-mini" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4o-mini'); ?>>GPT-4o-mini (Cepat & Sangat Murah)</option>
                                                <option value="gpt-4o" <?php selected(isset($settings['openai_model']) ? $settings['openai_model'] : '', 'gpt-4o'); ?>>GPT-4o (Kualitas Maksimal)</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- 3. Publishing & SEO Preferences -->
                        <div class="synka-card">
                            <div class="synka-card-header">
                                <h2><span class="dashicons dashicons-admin-post"></span> 3. Preferensi Konten & SEO</h2>
                            </div>
                            <div class="synka-card-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="main_site_url">URL Website Publik (Frontend)</label></th>
                                        <td>
                                            <input type="url" name="synka_auto_seo_settings[main_site_url]" id="main_site_url" value="<?php echo esc_attr(!empty($settings['main_site_url']) ? $settings['main_site_url'] : 'https://clickku.id'); ?>" class="regular-text" placeholder="https://clickku.id">
                                            <p class="description">URL domain utama website frontend Anda (digunakan untuk pembuatan tautan internal link otomatis seperti <code>https://clickku.id/web-development</code> alih-alih URL CMS).</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="post_status">Status Post Bawaan</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[post_status]" id="post_status" class="regular-text">
                                                <option value="publish" <?php selected(!isset($settings['post_status']) || $settings['post_status'] === 'publish' || empty($settings['post_status']), true); ?>>Langsung Publish (Rekomendasi)</option>
                                                <option value="draft" <?php selected(isset($settings['post_status']) && $settings['post_status'] === 'draft', true); ?>>Simpan sebagai Draft</option>
                                            </select>
                                            <p class="description">Jika baris spreadsheet memiliki kolom "Jadwal" yang terisi, post otomatis diatur ke status Terjadwal (Future).</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="default_author">Penulis (Author)</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[default_author]" id="default_author" class="regular-text">
                                                <?php foreach ($authors as $user): ?>
                                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(isset($settings['default_author']) ? $settings['default_author'] : 1, $user->ID); ?>>
                                                        <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="default_category">Kategori Fallback</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[default_category]" id="default_category" class="regular-text">
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(isset($settings['default_category']) ? $settings['default_category'] : 1, $cat->term_id); ?>>
                                                        <?php echo esc_html($cat->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="article_length">Target Panjang Artikel</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[article_length]" id="article_length" class="regular-text">
                                                <option value="800-1200" <?php selected(isset($settings['article_length']) ? $settings['article_length'] : '', '800-1200'); ?>>Pendek - Menengah (800 - 1.200 kata)</option>
                                                <option value="1200-1800" <?php selected(isset($settings['article_length']) ? $settings['article_length'] : '', '1200-1800'); ?>>Pilar Standar SEO (1.200 - 1.800 kata) - Rekomendasi</option>
                                                <option value="1800-2500" <?php selected(isset($settings['article_length']) ? $settings['article_length'] : '', '1800-2500'); ?>>Mendalam / Pillar Comprehensive (1.800 - 2.500 kata)</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="article_language">Bahasa Tulisan</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[article_language]" id="article_language" class="regular-text">
                                                <option value="id" <?php selected(isset($settings['article_language']) ? $settings['article_language'] : '', 'id'); ?>>Bahasa Indonesia</option>
                                                <option value="en" <?php selected(isset($settings['article_language']) ? $settings['article_language'] : '', 'en'); ?>>English</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Featured Image</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="synka_auto_seo_settings[enable_image]" value="1" <?php checked(isset($settings['enable_image']) ? $settings['enable_image'] : 1, 1); ?>>
                                                Otomatis download dan pasang Featured Image bebas royalti ke WP Media Library
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="unsplash_api_key">Unsplash Access Key (Opsional)</label></th>
                                        <td>
                                            <input type="text" name="synka_auto_seo_settings[unsplash_api_key]" id="unsplash_api_key" value="<?php echo esc_attr(isset($settings['unsplash_api_key']) ? $settings['unsplash_api_key'] : ''); ?>" class="large-text" placeholder="Access Key dari Unsplash Developers">
                                            <p class="description">Kosongkan jika ingin menggunakan gambar bebas lisensi resolusi tinggi secara otomatis.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- 4. Automation & Cron -->
                        <div class="synka-card">
                            <div class="synka-card-header">
                                <h2><span class="dashicons dashicons-clock"></span> 4. Penjadwalan Otomatis (WP-Cron)</h2>
                            </div>
                            <div class="synka-card-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="cron_interval">Interval Pengecekan</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[cron_interval]" id="cron_interval" class="regular-text">
                                                <option value="every_15_mins" <?php selected(isset($settings['cron_interval']) ? $settings['cron_interval'] : '', 'every_15_mins'); ?>>Setiap 15 Menit</option>
                                                <option value="every_30_mins" <?php selected(isset($settings['cron_interval']) ? $settings['cron_interval'] : '', 'every_30_mins'); ?>>Setiap 30 Menit</option>
                                                <option value="hourly" <?php selected(isset($settings['cron_interval']) ? $settings['cron_interval'] : '', 'hourly'); ?>>Setiap Jam (Hourly) - Rekomendasi</option>
                                                <option value="twicedaily" <?php selected(isset($settings['cron_interval']) ? $settings['cron_interval'] : '', 'twicedaily'); ?>>2 Kali Sehari</option>
                                                <option value="daily" <?php selected(isset($settings['cron_interval']) ? $settings['cron_interval'] : '', 'daily'); ?>>1 Kali Sehari (Daily)</option>
                                            </select>
                                            <p class="description">
                                                Status Eksekusi Berikutnya: 
                                                <strong><?php echo $next_cron ? date('d M Y H:i:s', $next_cron) . ' (' . human_time_diff($next_cron) . ' lagi)' : 'Belum Terjadwal'; ?></strong>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="cron_batch_limit">Batas Batch per Eksekusi</label></th>
                                        <td>
                                            <select name="synka_auto_seo_settings[cron_batch_limit]" id="cron_batch_limit" class="regular-text">
                                                <option value="1" <?php selected(isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5', '1'); ?>>1 Artikel per eksekusi</option>
                                                <option value="3" <?php selected(isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5', '3'); ?>>3 Artikel per eksekusi</option>
                                                <option value="5" <?php selected(isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5', '5'); ?>>5 Artikel per eksekusi (Rekomendasi)</option>
                                                <option value="10" <?php selected(isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5', '10'); ?>>10 Artikel per eksekusi</option>
                                                <option value="all" <?php selected(isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5', 'all'); ?>>Semua Artikel Ready sekaligus</option>
                                            </select>
                                            <p class="description">Menentukan berapa banyak baris artikel 'Ready' yang diproses sekaligus ketika jadwal cron berjalan.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php submit_button(__('Simpan Pengaturan', 'synka-auto-seo'), 'primary large'); ?>
                    </form>
                </div>

                <!-- RIGHT: ACTIVITY LOGS & STATUS -->
                <div class="synka-col-sidebar">
                    <div class="synka-card">
                        <div class="synka-card-header synka-flex-between">
                            <h2><span class="dashicons dashicons-list-view"></span> Riwayat & Log Aktivitas</h2>
                            <button type="button" id="btn-clear-logs" class="button button-small">Hapus Log</button>
                        </div>
                        <div class="synka-card-body synka-log-container">
                            <?php if (!empty($logs)): ?>
                                <ul class="synka-log-list">
                                    <?php foreach ($logs as $log): ?>
                                        <li class="log-item log-<?php echo esc_attr($log['type']); ?>">
                                            <div class="log-meta">
                                                <span class="log-badge log-badge-<?php echo esc_attr($log['type']); ?>"><?php echo esc_html(strtoupper($log['type'])); ?></span>
                                                <span class="log-time"><?php echo esc_html($log['time']); ?></span>
                                            </div>
                                            <div class="log-msg"><?php echo wp_kses_post($log['message']); ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="synka-empty-state">Belum ada riwayat aktivitas generate. Klik tombol <em>"Generate Sekarang"</em> untuk memulai.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL: GOOGLE APPS SCRIPT TEMPLATE GUIDE -->
        <div id="synka-script-modal" class="synka-modal" style="display:none;">
            <div class="synka-modal-content">
                <div class="synka-modal-header">
                    <h3>Panduan Setup Google Spreadsheet & Apps Script</h3>
                    <button type="button" class="synka-modal-close">&times;</button>
                </div>
                <div class="synka-modal-body">
                    <h4>1. Buat Google Spreadsheet dengan Header Kolom Berikut (Baris 1):</h4>
                    <div class="synka-code-box">
                        <code>Keyword Utama | Kategori | Jadwal | Outline / Catatan | Tone | Status | URL Hasil Post</code>
                    </div>
                    
                    <h4>2. Buka Menu <em>Extensions &gt; Apps Script</em> di Spreadsheet Anda</h4>
                    <p>Hapus semua kode yang ada, lalu salin dan tempel kode JavaScript di bawah ini:</p>
                    
                    <textarea readonly class="synka-script-textarea" rows="12"><?php 
                        $script_path = SYNKA_AUTO_SEO_PATH . 'google-apps-script-template.js';
                        if (file_exists($script_path)) {
                            echo esc_textarea(file_get_contents($script_path));
                        }
                    ?></textarea>
                    
                    <h4>3. Deploy sebagai Web App</h4>
                    <ol>
                        <li>Klik tombol <strong>Deploy &gt; New deployment</strong>.</li>
                        <li>Pilih type: <strong>Web app</strong>.</li>
                        <li>Execute as: <strong>Me</strong>.</li>
                        <li>Who has access: <strong>Anyone</strong> (Penting!).</li>
                        <li>Klik <strong>Deploy</strong>, lalu salin URL Web App (berakhiran <code>/exec</code>) dan masukkan ke kolom URL Pengaturan Plugin di atas.</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Test Connection to Sheets and AI
     */
    public function ajax_test_connection() {
        check_ajax_referer('synka_auto_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        // Test Sheets
        $rows = $this->sheets->get_pending_rows();
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => 'Koneksi Spreadsheet Gagal: ' . $rows->get_error_message()]);
        }

        $pending_count = is_array($rows) ? count($rows) : 0;

        wp_send_json_success([
            'count'   => $pending_count,
            'message' => sprintf('Koneksi Spreadsheet Berhasil! Ditemukan %d baris artikel dengan status Ready/Pending.', $pending_count)
        ]);
    }

    /**
     * AJAX: Get Pending List of Rows
     */
    public function ajax_get_pending_list() {
        check_ajax_referer('synka_auto_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $rows = $this->sheets->get_pending_rows();
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => $rows->get_error_message()]);
        }

        if (empty($rows) || !is_array($rows)) {
            wp_send_json_success([
                'count' => 0,
                'rows'  => []
            ]);
        }

        wp_send_json_success([
            'count' => count($rows),
            'rows'  => $rows
        ]);
    }

    /**
     * AJAX: Process a Single Specific Row (Used in Progressive Batch AJAX)
     */
    public function ajax_process_single_row() {
        check_ajax_referer('synka_auto_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $row = isset($_POST['row']) ? (array)$_POST['row'] : [];

        if (empty($row) || empty($row['keyword'])) {
            wp_send_json_error(['message' => 'Data baris artikel tidak valid atau keyword kosong.']);
        }

        $result = $this->scheduler->process_single_row($row);

        if (!$result['success']) {
            wp_send_json_error([
                'keyword' => isset($row['keyword']) ? $row['keyword'] : '',
                'message' => isset($result['message']) ? $result['message'] : 'Gagal memproses artikel.'
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Manual Run
     */
    public function ajax_manual_run() {
        check_ajax_referer('synka_auto_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(600);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $limit_param = isset($_POST['limit']) ? sanitize_text_field($_POST['limit']) : '1';
        $limit = ($limit_param === 'all') ? 9999 : (int)$limit_param;
        if ($limit <= 0) $limit = 1;

        $result = $this->scheduler->run_scheduler_job($limit, true);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }

        if (empty($result['processed'])) {
            $err_msg = 'Tidak ada artikel berstatus Ready / waktu jadwal belum tiba di Spreadsheet.';
            if (!empty($result['results']) && is_array($result['results'])) {
                foreach ($result['results'] as $res) {
                    if (isset($res['status']) && $res['status'] === 'error' && !empty($res['message'])) {
                        $err_msg = 'Gagal: ' . $res['message'];
                        break;
                    }
                }
            } elseif (!empty($result['message'])) {
                $err_msg = $result['message'];
            }
            wp_send_json_error(['message' => $err_msg]);
        }

        wp_send_json_success([
            'processed' => $result['processed'],
            'results'   => $result['results'],
            'message'   => sprintf('Berhasil memproses %d artikel!', $result['processed'])
        ]);
    }

    /**
     * AJAX: Clear Logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('synka_auto_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        update_option('synka_auto_seo_logs', []);
        wp_send_json_success(['message' => 'Log aktivitas berhasil dibersihkan.']);
    }
}
