<?php
/**
 * Synka Auto SEO - Cron Scheduler & Automation Processor
 * Executes scheduled article generation jobs via WP-Cron or Manual Trigger.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_Scheduler {

    private $sheets;
    private $ai;
    private $post_creator;
    const CRON_HOOK = 'synka_auto_seo_cron_event';

    public function __construct($sheets, $ai, $post_creator) {
        $this->sheets = $sheets;
        $this->ai = $ai;
        $this->post_creator = $post_creator;

        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action(self::CRON_HOOK, [$this, 'run_scheduler_job']);
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_15_mins'] = [
            'interval' => 15 * 60,
            'display'  => __('Setiap 15 Menit', 'synka-auto-seo')
        ];
        $schedules['every_30_mins'] = [
            'interval' => 30 * 60,
            'display'  => __('Setiap 30 Menit', 'synka-auto-seo')
        ];
        return $schedules;
    }

    /**
     * Register cron event
     */
    public function register_cron() {
        $settings = get_option('synka_auto_seo_settings', []);
        $interval = !empty($settings['cron_interval']) ? $settings['cron_interval'] : 'hourly';

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
        }
    }

    /**
     * Clear scheduled cron
     */
    public function clear_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Process a single row from Spreadsheet
     *
     * @param array $row Row data
     * @return array Result array with status and details
     */
    public function process_single_row($row) {
        @set_time_limit(240);
        $keyword = !empty($row['keyword']) ? $row['keyword'] : 'Artikel';
        $row_index = !empty($row['row_index']) ? (int)$row['row_index'] : 0;

        $this->add_log('info', "Memulai generate artikel untuk: '{$keyword}' (Baris #{$row_index})...");

        // 1. Generate Article using AI
        $ai_data = $this->ai->generate_article($row);

        if (is_wp_error($ai_data)) {
            $err = $ai_data->get_error_message();
            $this->add_log('error', "Gagal generate AI untuk '{$keyword}': {$err}");
            return [
                'success'   => false,
                'status'    => 'error',
                'keyword'   => $keyword,
                'row_index' => $row_index,
                'message'   => $err
            ];
        }

        // 2. Create WordPress Post
        $post_id = $this->post_creator->create_post($ai_data, $row);

        if (is_wp_error($post_id)) {
            $err = $post_id->get_error_message();
            $this->add_log('error', "Gagal membuat post WordPress untuk '{$keyword}': {$err}");
            return [
                'success'   => false,
                'status'    => 'error',
                'keyword'   => $keyword,
                'row_index' => $row_index,
                'message'   => $err
            ];
        }

        $post_url = get_permalink($post_id);

        // 3. Update Status Back to Spreadsheet
        if ($row_index > 0) {
            $this->sheets->update_row_status($row_index, 'Published', $post_url, $post_id);
        }

        $title = !empty($ai_data['title']) ? $ai_data['title'] : $keyword;
        $this->add_log('success', "Berhasil publish artikel: <a href='{$post_url}' target='_blank'>{$title}</a> (ID: {$post_id})");

        return [
            'success'   => true,
            'status'    => 'success',
            'keyword'   => $keyword,
            'row_index' => $row_index,
            'post_id'   => $post_id,
            'post_url'  => $post_url,
            'title'     => $title
        ];
    }

    /**
     * Main Scheduler Job Handler
     *
     * @param int|null $limit Max articles to process in this run (null = read from settings)
     * @param bool $ignore_schedule Force generate now ignoring schedule timestamp
     * @return array Results summary
     */
    public function run_scheduler_job($limit = null, $ignore_schedule = false) {
        @set_time_limit(600); // 10 minutes max execution time
        $settings = get_option('synka_auto_seo_settings', []);

        if ($limit === null) {
            $batch_setting = isset($settings['cron_batch_limit']) ? $settings['cron_batch_limit'] : '5';
            $limit = ($batch_setting === 'all') ? 9999 : (int)$batch_setting;
            if ($limit <= 0) $limit = 5;
        }

        $rows = $this->sheets->get_pending_rows();

        if (is_wp_error($rows)) {
            $this->add_log('error', 'Gagal membaca spreadsheet: ' . $rows->get_error_message());
            return [
                'success' => false,
                'message' => $rows->get_error_message()
            ];
        }

        if (empty($rows)) {
            return [
                'success'   => true,
                'processed' => 0,
                'message'   => 'Tidak ada artikel pending yang siap diproses di Spreadsheet.'
            ];
        }

        $processed = 0;
        $results = [];
        $now = current_time('timestamp');

        foreach ($rows as $row) {
            if ($processed >= $limit) break;

            // Check if schedule timestamp has arrived (if not manual run)
            if (!$ignore_schedule && !empty($row['schedule'])) {
                $schedule_time = strtotime($row['schedule'], $now);
                if ($schedule_time && $schedule_time > $now) {
                    // Not due yet, skip for automatic cron
                    continue;
                }
            }

            $res = $this->process_single_row($row);
            $results[] = $res;
            if (!empty($res['success'])) {
                $processed++;
            }
        }

        return [
            'success'   => true,
            'processed' => $processed,
            'results'   => $results
        ];
    }

    /**
     * Add activity log
     */
    public function add_log($type, $message) {
        $logs = get_option('synka_auto_seo_logs', []);
        if (!is_array($logs)) $logs = [];

        array_unshift($logs, [
            'time'    => current_time('mysql'),
            'type'    => $type, // info, success, error
            'message' => $message
        ]);

        // Keep last 40 logs
        $logs = array_slice($logs, 0, 40);
        update_option('synka_auto_seo_logs', $logs);
    }
}
