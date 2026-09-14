<?php
/**
 * Synka Auto SEO - Featured Image Handler
 * Automatically downloads relevant royalty-free images and attaches them as WP Featured Images.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_Image_Handler {

    /**
     * Download and set featured image for a given post
     *
     * @param int    $post_id
     * @param string $keyword Search keyword for image
     * @param string $alt_text Alt text for SEO (Title of article)
     * @param string $image_prompt Detailed realistic photography prompt from AI
     * @return int|false Attachment ID or false
     */
    public function attach_featured_image($post_id, $keyword, $alt_text = '', $image_prompt = '') {
        $settings = get_option('synka_auto_seo_settings', []);
        $enable_image = isset($settings['enable_image']) ? (bool)$settings['enable_image'] : true;

        if (!$enable_image) {
            return false;
        }

        $image_url = $this->find_image_url($keyword, $alt_text, $settings, $image_prompt);
        if (empty($image_url)) {
            return false;
        }

        return $this->download_and_attach($post_id, $image_url, $alt_text ? $alt_text : $keyword);
    }

    /**
     * Find unique realistic high-resolution image URL matching the article title
     */
    private function find_image_url($keyword, $alt_text, $settings, $image_prompt = '') {
        $seed = abs(crc32($alt_text . $keyword));

        // Primary: Photorealistic AI Image Generator specifically matching the article title & context
        $ai_prompt_text = !empty($image_prompt) 
            ? $image_prompt 
            : ("A realistic modern professional photograph representing " . $alt_text . ", " . $keyword . ", clean composition, studio lighting");

        $width = !empty($settings['image_width']) ? (int)$settings['image_width'] : 1200;
        $height = !empty($settings['image_height']) ? (int)$settings['image_height'] : 675;

        $enhanced_prompt = "hyper-realistic 8k professional editorial photograph, " . $ai_prompt_text . ", natural cinematic lighting, clean background, sharp focus, 16:9 widescreen, highly detailed, photorealistic, no text watermark";
        $ai_image_url = "https://image.pollinations.ai/prompt/" . rawurlencode($enhanced_prompt) . "?width=" . $width . "&height=" . $height . "&nologo=true&seed=" . $seed . "&model=flux";

        return $ai_image_url;
    }

    /**
     * Reliable Fallback Photo Selector
     */
    private function get_fallback_photo($keyword, $alt_text, $settings) {
        $unsplash_key = isset($settings['unsplash_api_key']) ? trim($settings['unsplash_api_key']) : '';
        $seed = abs(crc32($alt_text . $keyword));

        if (!empty($unsplash_key)) {
            $query = urlencode($keyword);
            $api_url = "https://api.unsplash.com/search/photos?query={$query}&per_page=15&orientation=landscape";
            $res = wp_remote_get($api_url, [
                'timeout'   => 10,
                'sslverify' => false,
                'headers'   => [
                    'Authorization' => 'Client-ID ' . $unsplash_key
                ]
            ]);

            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($data['results']) && is_array($data['results'])) {
                    $random_idx = array_rand($data['results']);
                    if (!empty($data['results'][$random_idx]['urls']['regular'])) {
                        return $data['results'][$random_idx]['urls']['regular'];
                    }
                }
            }
        }

        $kw_lower = strtolower($keyword . ' ' . $alt_text);

        $pools = [
            'coding' => [
                'https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1555066931-4365d14bab8c?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1461749280684-dccba630e2f6?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1542838132-92c53300491e?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1504639725590-34d0984388bd?w=1200&h=675&fit=crop&q=80',
            ],
            'seo' => [
                'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1504868584819-f8e8b4b6d7e3?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1572021335469-31706a17aaef?w=1200&h=675&fit=crop&q=80',
            ],
            'web' => [
                'https://images.unsplash.com/photo-1547658719-da2b51169166?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1581291518655-9523c93269e4?w=1200&h=675&fit=crop&q=80',
            ],
            'business' => [
                'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1557804506-669a67965ba0?w=1200&h=675&fit=crop&q=80',
            ],
            'general' => [
                'https://images.unsplash.com/photo-1434030216411-0b793f4b4173?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=1200&h=675&fit=crop&q=80',
                'https://images.unsplash.com/photo-1518770660439-4636190af475?w=1200&h=675&fit=crop&q=80',
            ]
        ];

        foreach (['coding', 'seo', 'web', 'business'] as $cat) {
            if (strpos($kw_lower, $cat) !== false || 
               ($cat === 'coding' && (strpos($kw_lower, 'program') !== false || strpos($kw_lower, 'developer') !== false || strpos($kw_lower, 'belajar') !== false || strpos($kw_lower, 'code') !== false)) ||
               ($cat === 'seo' && (strpos($kw_lower, 'market') !== false || strpos($kw_lower, 'traffic') !== false || strpos($kw_lower, 'ranking') !== false || strpos($kw_lower, 'google') !== false)) ||
               ($cat === 'web' && (strpos($kw_lower, 'website') !== false || strpos($kw_lower, 'bikin') !== false || strpos($kw_lower, 'design') !== false || strpos($kw_lower, 'aplikasi') !== false))) {
                $pool = $pools[$cat];
                return $pool[$seed % count($pool)];
            }
        }

        $general_pool = $pools['general'];
        return $general_pool[$seed % count($general_pool)];
    }

    /**
     * Download image file and register into WP Media Library using media_handle_sideload
     */
    private function download_and_attach($post_id, $image_url, $alt_text) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 1. Download image to temporary file (from AI generator)
        $tmp_file = download_url($image_url, 40);

        // If AI generator timed out, fallback to reliable Unsplash photo
        if (is_wp_error($tmp_file)) {
            $settings = get_option('synka_auto_seo_settings', []);
            $fallback_url = $this->get_fallback_photo($alt_text, $alt_text, $settings);
            $tmp_file = download_url($fallback_url, 20);
            if (is_wp_error($tmp_file)) {
                return false;
            }
        }

        $clean_title = sanitize_title($alt_text);
        if (empty($clean_title)) {
            $clean_title = 'featured-image';
        }

        $file_array = [
            'name'     => $clean_title . '-' . time() . '.jpg',
            'tmp_name' => $tmp_file
        ];

        // 2. Sideload into WordPress Media Library
        $attach_id = media_handle_sideload($file_array, $post_id, $alt_text);

        if (is_wp_error($attach_id)) {
            @unlink($tmp_file);
            return false;
        }

        // 3. Set Alt Text, Title, and Featured Image (Thumbnail)
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        wp_update_post([
            'ID'         => $attach_id,
            'post_title' => sanitize_text_field($alt_text),
        ]);

        set_post_thumbnail($post_id, $attach_id);

        return $attach_id;
    }
}
