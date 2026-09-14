<?php
/**
 * Synka Auto SEO - WordPress Post Creator & SEO Meta Integrator
 * Creates WordPress posts with RankMath and Yoast SEO optimization.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_Post_Creator {

    private $image_handler;

    public function __construct($image_handler) {
        $this->image_handler = $image_handler;
    }

    /**
     * Create WordPress Post from AI Generated Payload and Sheet Row Data
     *
     * @param array $ai_data
     * @param array $row
     * @return int|WP_Error Post ID on success or WP_Error
     */
    public function create_post($ai_data, $row) {
        $settings = get_option('synka_auto_seo_settings', []);
        
        $title = !empty($ai_data['title']) ? $ai_data['title'] : ucwords($row['keyword']);
        $raw_content = !empty($ai_data['content']) ? $ai_data['content'] : '';
        
        // 1. Ensure Key Takeaways blockquote is at the very top
        $raw_content = $this->ensure_key_takeaways_top($raw_content, $ai_data, $row);

        // 2. Append FAQ section to content if available
        $content = $this->append_faq_section($raw_content, isset($ai_data['faqs']) ? $ai_data['faqs'] : []);
        
        // 3. Enforce User Custom Anchor Text & Links (if provided in Outline)
        $content = $this->enforce_user_custom_links($content, $row);

        // 4. Ensure contextual internal links (only if user did NOT provide custom links)
        $content = $this->inject_smart_internal_links($content, $row);
        
        // Determine Post Author
        $author_id = !empty($settings['default_author']) ? (int)$settings['default_author'] : 1;

        // Determine Post Status & Date
        $status = 'publish';
        if (!empty($settings['post_status']) && $settings['post_status'] === 'draft') {
            $status = 'draft';
        }
        
        // If row status in spreadsheet is ready/publish, force publish
        if (!empty($row['status']) && in_array(strtolower($row['status']), ['ready', 'siap', 'publish', 'published', 'live'])) {
            $status = 'publish';
        }
        
        $post_date = current_time('mysql');
        $is_future = false;
        
        if (!empty($row['schedule'])) {
            $parsed_time = strtotime($row['schedule']);
            if ($parsed_time && $parsed_time > current_time('timestamp')) {
                $status = 'future';
                $post_date = date('Y-m-d H:i:s', $parsed_time);
                $is_future = true;
            }
        }

        // Determine Post Category
        $cat_ids = $this->resolve_categories($row['category'], $settings);

        // Prepare Post Array
        $post_args = [
            'post_title'    => wp_strip_all_tags($title),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_author'   => $author_id,
            'post_type'     => 'post',
            'post_category' => $cat_ids,
        ];

        if ($is_future) {
            $post_args['post_date']     = $post_date;
            $post_args['post_date_gmt'] = get_gmt_from_date($post_date);
        }

        // Insert Post
        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Directly enforce publish status in database to prevent capability downgrades
        if ($status === 'publish') {
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $post_id]);
            clean_post_cache($post_id);
        }

        // 1. Set RankMath SEO Metadata
        $this->set_rankmath_metadata($post_id, $ai_data, $row);

        // 2. Set Yoast SEO Metadata
        $this->set_yoast_metadata($post_id, $ai_data, $row);

        // 3. Attach Featured Image
        $image_kw = !empty($ai_data['image_keyword']) ? $ai_data['image_keyword'] : $row['keyword'];
        $image_prompt = !empty($ai_data['image_prompt']) ? $ai_data['image_prompt'] : '';
        $this->image_handler->attach_featured_image($post_id, $image_kw, $title, $image_prompt);

        return $post_id;
    }

    /**
     * Append formatted FAQ HTML and FAQPage Schema JSON-LD to post content
     */
    private function append_faq_section($content, $faqs) {
        if (empty($faqs) || !is_array($faqs)) {
            return $content;
        }

        $faq_html = "\n\n<!-- Synka Auto SEO FAQ Section -->\n";
        $faq_html .= "<h2>Frequently Asked Questions (FAQ)</h2>\n";
        $faq_html .= "<div class=\"synka-faq-wrapper\">\n";

        $schema_main_entity = [];

        foreach ($faqs as $faq) {
            $q = !empty($faq['question']) ? esc_html($faq['question']) : '';
            $a = !empty($faq['answer']) ? esc_html($faq['answer']) : '';
            if (empty($q) || empty($a)) continue;

            $faq_html .= "  <div class=\"faq-item\" style=\"margin-bottom: 16px; padding: 14px 18px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;\">\n";
            $faq_html .= "    <h3 style=\"margin-top: 0; font-size: 1.05rem; color: #0f172a;\">{$q}</h3>\n";
            $faq_html .= "    <p style=\"margin-bottom: 0; color: #475569;\">{$a}</p>\n";
            $faq_html .= "  </div>\n";

            $schema_main_entity[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $a
                ]
            ];
        }

        $faq_html .= "</div>\n";

        // Append Schema JSON-LD
        if (!empty($schema_main_entity)) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'FAQPage',
                'mainEntity' => $schema_main_entity
            ];
            $faq_html .= "\n<script type=\"application/ld+json\">\n" . wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
        }

        return $content . $faq_html;
    }

    /**
     * Resolve category name to ID or create if not exists
     */
    private function resolve_categories($cat_name, $settings) {
        $default_cat = !empty($settings['default_category']) ? (int)$settings['default_category'] : 1;

        if (empty($cat_name)) {
            return [$default_cat];
        }

        $term = get_term_by('name', $cat_name, 'category');
        if ($term) {
            return [$term->term_id];
        }

        // Try creating term
        $new_term = wp_insert_term($cat_name, 'category');
        if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
            return [(int)$new_term['term_id']];
        }

        return [$default_cat];
    }

    /**
     * Set RankMath SEO Metadata
     */
    private function set_rankmath_metadata($post_id, $ai_data, $row) {
        $focus_kw = !empty($ai_data['focus_keyword']) ? $ai_data['focus_keyword'] : (!empty($row['keyword']) ? $row['keyword'] : '');
        $meta_title = !empty($ai_data['meta_title']) ? $ai_data['meta_title'] : (!empty($ai_data['title']) ? $ai_data['title'] : $row['keyword']);
        $meta_desc = !empty($ai_data['meta_description']) ? $ai_data['meta_description'] : '';

        if (!empty($meta_title)) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($meta_title));
        }
        if (!empty($meta_desc)) {
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($meta_desc));
        }
        if (!empty($focus_kw)) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($focus_kw));
        }
        update_post_meta($post_id, 'rank_math_robots', ['index']);
    }

    /**
     * Set Yoast SEO Metadata
     */
    private function set_yoast_metadata($post_id, $ai_data, $row) {
        $focus_kw = !empty($ai_data['focus_keyword']) ? $ai_data['focus_keyword'] : (!empty($row['keyword']) ? $row['keyword'] : '');
        $meta_title = !empty($ai_data['meta_title']) ? $ai_data['meta_title'] : (!empty($ai_data['title']) ? $ai_data['title'] : $row['keyword']);
        $meta_desc = !empty($ai_data['meta_description']) ? $ai_data['meta_description'] : '';

        if (!empty($meta_title)) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($meta_title));
        }
        if (!empty($meta_desc)) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($meta_desc));
        }
        if (!empty($focus_kw)) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focus_kw));
        }
    }

    /**
     * Get public frontend site URL (handles headless/subdomain CMS setups like cms.clickku.id -> clickku.id)
     */
    private function get_public_site_url() {
        $settings = get_option('synka_auto_seo_settings', []);
        if (!empty($settings['main_site_url'])) {
            return rtrim($settings['main_site_url'], '/');
        }
        $site_url = home_url();
        $site_url = preg_replace('#^https?://(?:cms|wp|admin)\.#i', 'https://', $site_url);
        return rtrim($site_url, '/');
    }

    /**
     * Enforce User-Specified Custom Anchor Texts and URLs strictly
     * Strips any unauthorized AI-generated links and ensures user's custom links are applied.
     */
    private function enforce_user_custom_links($content, $row) {
        $outline = !empty($row['outline']) ? $row['outline'] : '';
        if (empty($outline)) return $content;

        // Parse custom URLs from outline
        $custom_links = [];
        if (preg_match_all('#https?://[^\s,"\'<>)]+#i', $outline, $urls)) {
            foreach ($urls[0] as $url) {
                $found_url = rtrim($url, '.,;)');
                $anchor = '';
                if (preg_match('/anchor(?:\s*text)?\s*[:=]?\s*["\']([^"\']+)["\']/i', $outline, $am)) {
                    $anchor = trim($am[1]);
                } elseif (preg_match('/["\']([^"\']+)["\']\s*(?:ke|to|link|->|=)?\s*' . preg_quote($found_url, '/') . '/i', $outline, $am)) {
                    $anchor = trim($am[1]);
                }
                $custom_links[] = [
                    'url'    => $found_url,
                    'anchor' => $anchor
                ];
            }
        }

        if (empty($custom_links)) {
            return $content;
        }

        $valid_urls = array_map(function($item) { return strtolower(rtrim($item['url'], '/')); }, $custom_links);

        // 1. Inject custom links if the anchor text is found in content but not yet linked
        foreach ($custom_links as $cl) {
            $url = $cl['url'];
            $anchor = $cl['anchor'];

            if (strpos(strtolower($content), strtolower($url)) !== false) {
                continue; // Already contains the target URL
            }

            if (!empty($anchor)) {
                $pattern = '/(?!(?:[^<]+>|[^>]+<\/a>))\b(' . preg_quote($anchor, '/') . ')\b/i';
                if (preg_match($pattern, $content)) {
                    $replacement = '<a href="' . esc_url($url) . '">$1</a>';
                    $content = preg_replace($pattern, $replacement, $content, 1);
                } else {
                    // Append natural link callout to the first paragraph
                    $content = preg_replace('/(<\/p>)/i', ' Pelajari lebih lanjut mengenai <a href="' . esc_url($url) . '">' . esc_html($anchor) . '</a>.$1', $content, 1);
                }
            }
        }

        // 2. Strip any unauthorized <a> tags generated by AI that don't match the user's custom URLs
        $content = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', function($m) use ($valid_urls) {
            $href = strtolower(rtrim($m[1], '/'));
            $matched = false;
            foreach ($valid_urls as $v_url) {
                if ($v_url === $href || strpos($href, $v_url) !== false) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                return $m[0]; // Keep authorized user link
            }
            return $m[2]; // Strip unwanted link, keep anchor text
        }, $content);

        return $content;
    }

    /**
     * Smart internal linker fallback: ensures articles always contain contextual internal links with anchor texts
     * Only runs if the user has NOT provided custom links/anchor texts in Outline
     */
    private function inject_smart_internal_links($content, $row) {
        if (empty($content)) return $content;

        // 1. Check if user provided custom links or anchor text instructions in Outline
        $outline = !empty($row['outline']) ? $row['outline'] : '';
        $has_custom_links = preg_match('#https?://#i', $outline) || preg_match('/anchor|link|tautan|url/i', $outline);
        if ($has_custom_links) {
            // User provided custom anchor text / links - DO NOT inject default automatic links!
            return $content;
        }

        // 2. If content already has any HTML links (<a href=), do not add extra unwanted links
        if (preg_match('/<a\s+[^>]*href=/i', $content)) {
            return $content;
        }

        $site_url = $this->get_public_site_url();

        // Target keywords and URLs matching Clickku website pages
        $target_keywords = [
            'jasa pembuatan website'   => "{$site_url}/web-development",
            'pembuatan website'        => "{$site_url}/web-development",
            'web development'          => "{$site_url}/web-development",
            'bikin website'            => "{$site_url}/web-development",
            'jasa seo profesional'     => "{$site_url}/seo",
            'layanan seo'              => "{$site_url}/seo",
            'jasa seo'                 => "{$site_url}/seo",
            'digital marketing'        => "{$site_url}/digital-marketing",
            'jasa digital marketing'   => "{$site_url}/digital-marketing",
            'social media marketing'   => "{$site_url}/social-media",
            'jasa social media'        => "{$site_url}/social-media",
            'artikel insight'          => "{$site_url}/insight",
            'insight digital'          => "{$site_url}/insight",
            'portofolio'               => "{$site_url}/portofolio",
            'glosarium'                => "{$site_url}/glosarium",
            'tutorial'                 => "{$site_url}/tutorial",
        ];

        $links_added = 0;
        foreach ($target_keywords as $phrase => $link_url) {
            if ($links_added >= 3) break;

            // Pattern that matches the phrase only when not inside an <a> tag
            $pattern = '/(?!(?:[^<]+>|[^>]+<\/a>))\b(' . preg_quote($phrase, '/') . ')\b/i';
            if (preg_match($pattern, $content)) {
                $replacement = '<a href="' . esc_url($link_url) . '">$1</a>';
                $content = preg_replace($pattern, $replacement, $content, 1);
                $links_added++;
            }
        }

        return $content;
    }

    /**
     * Ensure Key Takeaways blockquote is at the very top of content
     */
    private function ensure_key_takeaways_top($content, $ai_data, $row) {
        if (empty($content)) return $content;

        // 1. Strip any existing Key Takeaways blockquote or headings in the content to avoid duplicate
        $clean_content = preg_replace('/<blockquote[\s\S]*?(?:Key Takeaways|Poin Kunci|💡)[\s\S]*?<\/blockquote>\s*/i', '', $content);
        $clean_content = preg_replace('/<h[23]>[\s\S]*?(?:Key Takeaways|Poin Kunci)[\s\S]*?<\/h[23]>\s*(?:<ul>[\s\S]*?<\/ul>|<p>[\s\S]*?<\/p>)?\s*/i', '', $clean_content);

        // 2. Extract bullet items from ai_data['key_takeaways'] or from raw content
        $bullets = [];
        if (!empty($ai_data['key_takeaways']) && is_array($ai_data['key_takeaways'])) {
            foreach ($ai_data['key_takeaways'] as $item) {
                $clean_item = trim(strip_tags($item));
                if (!empty($clean_item)) {
                    $bullets[] = $clean_item;
                }
            }
        }

        // If bullets still empty, try extracting from original content blockquote
        if (empty($bullets) && preg_match('/<blockquote[\s\S]*?>([\s\S]*?)<\/blockquote>/i', $content, $bq_match)) {
            if (preg_match_all('/<li>(.*?)<\/li>/i', $bq_match[1], $li_matches)) {
                foreach ($li_matches[1] as $li) {
                    $clean_li = trim(strip_tags($li));
                    if (!empty($clean_li)) {
                        $bullets[] = $clean_li;
                    }
                }
            }
            $clean_content = str_replace($bq_match[0], '', $clean_content);
        }

        // Fallback bullets if AI didn't provide any
        if (empty($bullets)) {
            $kw = !empty($row['keyword']) ? $row['keyword'] : 'topik ini';
            $bullets = [
                "Memahami esensi utama dan panduan implementasi strategis dari {$kw}.",
                "Faktor penentu keberhasilan, data relevan, dan analisis mendalam untuk hasil terukur.",
                "Rekomendasi taktis dan langkah aksi praktis yang dapat langsung diterapkan."
            ];
        }

        // Build premium Key Takeaways blockquote HTML
        $items_html = '';
        foreach ($bullets as $b) {
            $items_html .= "    <li>" . esc_html($b) . "</li>\n";
        }

        $bq_html = "<blockquote>\n"
                 . "  <p><strong>💡 Key Takeaways (Poin Kunci):</strong></p>\n"
                 . "  <ul>\n"
                 . $items_html
                 . "  </ul>\n"
                 . "</blockquote>\n\n";

        return $bq_html . trim($clean_content);
    }
}
