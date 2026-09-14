<?php
/**
 * Synka Auto SEO - Google Sheets Connector
 * Handles reading scheduled rows and updating status back to Google Sheets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_Sheets_Connector {

    /**
     * Fetch pending rows ready for generation from Google Sheets
     *
     * @return array|WP_Error Array of rows or WP_Error on failure
     */
    public function get_pending_rows() {
        $settings = get_option('synka_auto_seo_settings', []);
        $sheet_url = isset($settings['sheet_url']) ? trim($settings['sheet_url']) : '';
        $mode = isset($settings['sheet_mode']) ? $settings['sheet_mode'] : 'apps_script';

        if (empty($sheet_url)) {
            return new WP_Error('empty_url', __('URL Google Spreadsheet / Webhook belum dikonfigurasi.', 'synka-auto-seo'));
        }

        if ($mode === 'apps_script') {
            return $this->fetch_from_apps_script($sheet_url);
        } else {
            return $this->fetch_from_published_csv($sheet_url);
        }
    }

    /**
     * Fetch data via Google Apps Script Webhook (JSON)
     */
    private function fetch_from_apps_script($webhook_url) {
        // Append action=get_pending if not present
        $url = add_query_arg(['action' => 'get_pending'], $webhook_url);

        $response = wp_remote_get($url, [
            'timeout' => 25,
            'sslverify' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200 || empty($body)) {
            return new WP_Error('http_error', sprintf(__('Gagal menghubungi Webhook Apps Script (HTTP %d).', 'synka-auto-seo'), $code));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('invalid_json', __('Respon dari Apps Script bukan format JSON yang valid.', 'synka-auto-seo'));
        }

        if (isset($data['status']) && $data['status'] === 'error') {
            return new WP_Error('sheet_error', isset($data['message']) ? $data['message'] : __('Terjadi error pada Spreadsheet.', 'synka-auto-seo'));
        }

        $rows = isset($data['data']) ? $data['data'] : $data;
        return $this->standardize_rows($rows);
    }

    /**
     * Fetch data from Published CSV URL
     */
    private function fetch_from_published_csv($csv_url) {
        $url = add_query_arg(['_t' => time()], $csv_url);

        $response = wp_remote_get($url, [
            'timeout'   => 25,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200 || empty($body)) {
            return new WP_Error('http_error', sprintf(__('Gagal mengambil data CSV Google Sheet (HTTP %d).', 'synka-auto-seo'), $code));
        }

        // Normalize line breaks
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        $lines = explode("\n", $body);
        if (count($lines) < 2) {
            return [];
        }

        $header_line = array_shift($lines);
        $header = str_getcsv($header_line);
        $header = array_map(function($h) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $h)));
        }, $header);

        $header_count = count($header);
        $rows = [];
        $row_index = 2; // Row 1 is header

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (empty($data) || count(array_filter($data)) === 0) continue;

            // Pad or slice $data so array_combine never fails if trailing columns are empty
            if (count($data) < $header_count) {
                $data = array_pad($data, $header_count, '');
            } elseif (count($data) > $header_count) {
                $data = array_slice($data, 0, $header_count);
            }

            $row = array_combine($header, $data);
            $row['row_index'] = $row_index;
            $rows[] = $row;
            $row_index++;
        }

        return $this->standardize_rows($rows);
    }

    /**
     * Standardize array keys and filter only "Ready" / "Pending" status
     */
    private function standardize_rows($raw_rows) {
        $standardized = [];

        foreach ($raw_rows as $idx => $row) {
            // Build normalized key-value map
            $clean_row = [];
            foreach ($row as $k => $v) {
                $clean_key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$k));
                $clean_row[$clean_key] = is_string($v) ? trim($v) : $v;
            }

            // Find keyword
            $keyword = '';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'keyword') !== false || strpos($k, 'topik') !== false || strpos($k, 'topic') !== false || strpos($k, 'judul') !== false || strpos($k, 'title') !== false) {
                    if (!empty($v)) {
                        $keyword = (string)$v;
                        break;
                    }
                }
            }

            if (empty($keyword)) continue;

            // Find category
            $category = '';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'kategori') !== false || strpos($k, 'category') !== false || $k === 'cat') {
                    if (!empty($v)) {
                        $category = (string)$v;
                        break;
                    }
                }
            }

            // Find status
            $status = 'ready';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'status') !== false || strpos($k, 'state') !== false) {
                    if (!empty($v)) {
                        $status = strtolower(trim((string)$v));
                        break;
                    }
                }
            }

            // Filter only pending / ready / queued / siap
            if (!in_array($status, ['ready', 'pending', 'queued', 'siap', 'draft'])) {
                continue;
            }

            // Find schedule date/time
            $schedule = '';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'jadwal') !== false || strpos($k, 'schedule') !== false || strpos($k, 'tanggal') !== false || strpos($k, 'waktu') !== false) {
                    if (!empty($v)) {
                        $schedule = (string)$v;
                        break;
                    }
                }
            }

            // Find subtopics / notes / outline
            $outline = '';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'outline') !== false || strpos($k, 'catatan') !== false || strpos($k, 'subtopik') !== false || strpos($k, 'notes') !== false || strpos($k, 'instruksi') !== false) {
                    if (!empty($v)) {
                        $outline = (string)$v;
                        break;
                    }
                }
            }

            // Find Tone
            $tone = 'Profesional, Informatif & Edukatif';
            foreach ($clean_row as $k => $v) {
                if (strpos($k, 'tone') !== false || strpos($k, 'gaya') !== false || strpos($k, 'style') !== false) {
                    if (!empty($v)) {
                        $tone = (string)$v;
                        break;
                    }
                }
            }

            $standardized[] = [
                'row_index' => isset($row['row_index']) ? (int)$row['row_index'] : ($idx + 2),
                'keyword'   => $keyword,
                'category'  => $category,
                'outline'   => $outline,
                'tone'      => $tone,
                'schedule'  => $schedule,
                'status'    => $status,
            ];
        }

        return $standardized;
    }

    /**
     * Update row status back to Google Spreadsheet via Apps Script Webhook
     */
    public function update_row_status($row_index, $status = 'Published', $post_url = '', $post_id = 0) {
        $settings = get_option('synka_auto_seo_settings', []);
        $sheet_url = isset($settings['sheet_url']) ? trim($settings['sheet_url']) : '';
        $mode = isset($settings['sheet_mode']) ? $settings['sheet_mode'] : 'apps_script';

        if ($mode !== 'apps_script' || empty($sheet_url)) {
            return false; // Published CSV is read-only
        }

        $payload = [
            'action'    => 'update_status',
            'row_index' => $row_index,
            'status'    => $status,
            'post_url'  => $post_url,
            'post_id'   => $post_id,
            'time'      => current_time('mysql'),
        ];

        $response = wp_remote_post($sheet_url, [
            'timeout' => 20,
            'sslverify' => false,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('Synka Auto SEO Update Status Error: ' . $response->get_error_message());
            return false;
        }

        return true;
    }
}
