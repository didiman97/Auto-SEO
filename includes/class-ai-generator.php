<?php
/**
 * Synka Auto SEO - AI Content Generator Engine
 * Connects to Google Gemini API or OpenAI API with SEO E-E-A-T prompt engineering.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Synka_Auto_SEO_AI_Generator {

    /**
     * Generate complete SEO article package from a row
     *
     * @param array $row Row data from spreadsheet
     * @return array|WP_Error Generated article payload
     */
    public function generate_article($row) {
        $settings = get_option('synka_auto_seo_settings', []);
        $provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'gemini';

        if ($provider === 'gemini') {
            return $this->generate_with_gemini($row, $settings);
        } else {
            return $this->generate_with_openai($row, $settings);
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
        // Automatically strip cms., wp., or admin. subdomain to point to main frontend site
        $site_url = preg_replace('#^https?://(?:cms|wp|admin)\.#i', 'https://', $site_url);
        return rtrim($site_url, '/');
    }

    /**
     * Get internal links context for AI to naturally embed anchor texts
     */
    private function get_internal_links_context() {
        $links = [];
        $site_url = $this->get_public_site_url();

        // Main service and key pages matching Clickku website structure
        $links[] = "- Layanan Web Development / Pembuatan Website: {$site_url}/web-development (Contoh anchor: jasa pembuatan website, layanan web development, bikin website profesional, jasa web developer)";
        $links[] = "- Layanan Jasa SEO: {$site_url}/seo (Contoh anchor: jasa SEO profesional, layanan optimasi SEO, agensi SEO terpercaya, optimasi ranking Google)";
        $links[] = "- Layanan Digital Marketing: {$site_url}/digital-marketing (Contoh anchor: jasa digital marketing, strategi pemasaran digital, agensi iklan digital)";
        $links[] = "- Layanan Social Media: {$site_url}/social-media (Contoh anchor: jasa social media marketing, kelola akun sosmed bisnis)";
        $links[] = "- Halaman Blog & Insight: {$site_url}/insight (Contoh anchor: artikel insight digital marketing, panduan strategi bisnis)";
        $links[] = "- Halaman Portofolio: {$site_url}/portofolio (Contoh anchor: portofolio proyek website, hasil karya digital)";
        $links[] = "- Halaman Glosarium: {$site_url}/glosarium (Contoh anchor: istilah digital marketing, glosarium bisnis online)";
        $links[] = "- Halaman Tutorial: {$site_url}/tutorial (Contoh anchor: panduan tutorial praktis, tutorial digital)";

        // Fetch recent published blog articles
        if (function_exists('get_posts')) {
            $recent_posts = get_posts([
                'numberposts' => 5,
                'post_status' => 'publish',
                'orderby'     => 'date',
                'order'       => 'DESC'
            ]);

            if (!empty($recent_posts) && is_array($recent_posts)) {
                foreach ($recent_posts as $p) {
                    $p_title = get_the_title($p->ID);
                    $p_url = get_permalink($p->ID);
                    // Replace cms subdomain with public domain if applicable
                    if (!empty($settings['main_site_url']) || strpos($p_url, '://cms.') !== false || strpos($p_url, '://wp.') !== false) {
                        $p_url = preg_replace('#^https?://(?:cms|wp|admin)\.#i', 'https://', $p_url);
                    }
                    if ($p_url && $p_title) {
                        $links[] = "- Artikel Terkait: {$p_url} (Topik / Anchor: \"{$p_title}\")";
                    }
                }
            }
        }

        return implode("\n", $links);
    }

    /**
     * Parse custom link instructions from user outline
     */
    private function parse_custom_link_instructions($outline) {
        $found = [];
        if (preg_match_all('#https?://[^\s,"\'<>)]+#i', $outline, $urls)) {
            foreach ($urls[0] as $url) {
                $found_url = rtrim($url, '.,;)');
                $anchor = '';
                if (preg_match('/anchor(?:\s*text)?\s*[:=]?\s*["\']([^"\']+)["\']/i', $outline, $am)) {
                    $anchor = trim($am[1]);
                } elseif (preg_match('/["\']([^"\']+)["\']\s*(?:ke|to|link|->|=)?\s*' . preg_quote($found_url, '/') . '/i', $outline, $am)) {
                    $anchor = trim($am[1]);
                }
                $found[] = [
                    'url'    => $found_url,
                    'anchor' => $anchor,
                ];
            }
        }
        return $found;
    }

    /**
     * Build standard SEO & Generative Engine Optimization (GEO) prompt for AI
     */
    private function build_prompt($row, $settings) {
        $keyword = $row['keyword'];
        $outline = !empty($row['outline']) ? $row['outline'] : 'Buatkan struktur pembahasan yang komprehensif, aktual, solutif, dan mendalam.';
        $tone = !empty($row['tone']) ? $row['tone'] : 'Profesional, Tajam, namun Santai & Solutif (B2B/B2C)';
        $category = !empty($row['category']) ? $row['category'] : 'General';
        $language = isset($settings['article_language']) && $settings['article_language'] === 'en' ? 'Bahasa Inggris' : 'Bahasa Indonesia';
        $length = isset($settings['article_length']) ? $settings['article_length'] : '1200-1800';
        $internal_links_guide = $this->get_internal_links_context();

        // Check if user provided custom links/anchor text in Outline
        $custom_links = $this->parse_custom_link_instructions($outline);
        $has_custom_links = !empty($custom_links) || preg_match('#https?://#i', $outline) || preg_match('/anchor|link|tautan/i', $outline);
        
        if ($has_custom_links) {
            $custom_link_details = "";
            if (!empty($custom_links)) {
                foreach ($custom_links as $cl) {
                    $anchor_desc = !empty($cl['anchor']) ? "dengan anchor text persis: \"{$cl['anchor']}\"" : "(gunakan anchor text yang paling relevan)";
                    $custom_link_details .= "- Target URL: {$cl['url']} {$anchor_desc}\n";
                }
            } else {
                $custom_link_details = "- Ikuti URL dan anchor text yang ada di data Outline di atas.\n";
            }

            $link_rule = <<<LNK
4. INTERNAL LINKING & ANCHOR TEXT (PRIORITAS MUTLAK PENGGUNA):
   PENGGUNA TELAH MENETAPKAN LINK KHUSUS BERIKUT:
{$custom_link_details}
   ATURAN WAJIB:
   - Sisipkan tautan di atas persis ke dalam salah satu kalimat artikel menggunakan tag HTML `<a href="URL">ANCHOR_TEXT</a>`.
   - DILARANG KERAS membuat, mengarang, atau menambahkan link lain selain yang diminta di atas!
   - DILARANG mengubah URL tujuan maupun kata anchor text yang diminta pengguna.
LNK;
        } else {
            $link_rule = <<<LNK
4. INTERNAL LINKING & ANCHOR TEXT (KONTEKSTUAL & NATURAL):
   - Sisipkan 2 hingga 4 internal link secara natural di dalam paragraf artikel menggunakan tag HTML `<a href="...">anchor text relevan</a>`.
   - Gunakan anchor text bervariasi yang mengalir alami dengan kalimat.
   - Pilihan URL target internal website yang WAJIB digunakan:
{$internal_links_guide}
LNK;
        }

        $prompt = <<<EOT
Kamu adalah seorang Content Writer SEO Ahli dan Spesialis Generative Engine Optimization (GEO) berpengalaman internasional dalam menulis artikel peringkat teratas untuk audiens di Indonesia.
Tugasmu adalah menulis artikel pilar berperingkat tinggi dan ramah AI Engine (Google AI Overviews, Perplexity, SearchGPT) berdasarkan data berikut:

- Target Kata Kunci Utama: {$keyword}
- Kategori Artikel: {$category}
- Outline / Poin Wajib: {$outline}
- Nada Bahasa (Tone): {$tone}
- Search Intent: Informatif, Solutif, dan Actionable
- Panjang Artikel: Minimal {$length} kata
- Bahasa Tulisan: {$language}

INSTRUKSI STRUKTUR & GENERATIVE ENGINE OPTIMIZATION (GEO):

1. STRUKTUR SEO & FORMAT RAMAH AI:
   - Kembangkan Long-tail Keywords dan LSI Keywords yang relevan dan sebarkan secara alami di dalam artikel.
   - Format HTML Semantik: Gunakan tag <h2>, <h3>, <p>, <ul>, <ol>, <li>, <table>, <blockquote>, <strong>.
   - JANGAN gunakan tag <h1> di dalam content (hanya h2 dan h3).
   - Struktur Heading (Wajib Q&A): Sebagian besar H2 dan H3 diformat persis seperti pertanyaan yang sering dicari pengguna (misal: "Apa itu {$keyword}?", "Mengapa {$keyword} Penting?", "Bagaimana Cara Menerapkan {$keyword} Langkah demi Langkah?").
   - WAJIB: Jawab pertanyaan heading tersebut secara langsung dan tegas di kalimat pertama tepat di bawah heading sebelum penjabaran detail.

2. ELEMEN GENERATIVE ENGINE OPTIMIZATION (GEO):
   - KEY TAKEAWAYS DI PALING ATAS (WAJIB BLOCKQUOTE): Di baris pertama artikel (sebelum teks atau heading apapun), WAJIB buat kotak kutipan <blockquote> berisi Poin Kunci / Intisari Eksekutif artikel dengan format persis berikut:
     <blockquote>
       <p><strong>💡 Key Takeaways (Poin Kunci):</strong></p>
       <ul>
         <li>[Poin kunci 1: Jawaban langsung dan esensi utama topik]</li>
         <li>[Poin kunci 2: Fakta/data penting yang wajib diketahui]</li>
         <li>[Poin kunci 3: Rekomendasi tindakan atau solusi praktis]</li>
       </ul>
     </blockquote>
   - Quick Answer / The Snapshot: Tepat setelah blockquote Key Takeaways, jelaskan jawaban cepat dalam 40-50 kata secara padat tanpa basa-basi pembuka.
   - Otoritas & Sitasi (E-E-A-T): Wajib sertakan minimal 2 data statistik relevan (sebutkan nama institusi/sumber dan tahun 2023-2026) atau simulasi kutipan ahli industri untuk memperkuat argumen.
   - Tabel Perbandingan Teknis: Buat 1 tabel HTML mendalam (<table><thead>...</thead><tbody>...</tbody></table>) dengan parameter yang jelas (fitur/metode, kelebihan, kekurangan, rekomendasi implementasi).

3. GAYA PENULISAN & FORMULA PAS (PROBLEM, AGITATE, SOLUTION):
   - Lewati kata pengantar yang bertele-tele. Setelah Key Takeaways blockquote, buka pembahasan dengan formula PAS (Problem, Agitate, Solution) secara padat di paragraf pembuka.
   - Readability: Gunakan paragraf pendek (maksimal 2-3 kalimat per paragraf). Gunakan bullet points secara ekstensif untuk menjabarkan fitur, tips, atau langkah-langkah.
   - 100% Original, tajam, berbobot, dan mengalir alami.

{$link_rule}

5. FAQ (FREQUENTLY ASKED QUESTIONS):
   - Berikan minimal 3-5 pertanyaan & jawaban populer yang sering dicari pengguna terkait topik ini secara tuntas dan solutif.

6. META TAGS & IMAGE PROMPT:
   - meta_title: Maksimal 60 karakter, CTR-driven & mengandung keyword utama.
   - meta_description: Antara 140 - 155 karakter persuasif dengan Call-To-Action (CTA).
   - focus_keyword: {$keyword}
   - image_keyword: 2-3 kata kunci bahasa inggris untuk pencarian visual.
   - image_prompt: Prompt deskriptif detail dalam bahasa Inggris untuk membuat foto fotografi realistis profesional sesuai judul artikel (contoh: "A realistic modern editorial photograph representing {$keyword}, clean professional workspace, 8k resolution, cinematic natural lighting, 16:9 widescreen").

7. INSTRUKSI ANTI-FLUFF (LARANGAN KERAS):
   - DILARANG menggunakan frasa transisi AI klise seperti: "Dalam era digital ini", "Di dunia yang serba cepat ini", "Kesimpulannya", "Terlebih lagi", "Tidak dapat dipungkiri bahwa".
   - Gunakan transisi antar paragraf yang berfokus pada nilai dan kelanjutan logika.

FORMAT JSON OUTPUT YANG WAJIB DIHASILKAN (Strict JSON murni):
{
  "title": "Judul Artikel Menarik Mengandung Keyword",
  "meta_title": "Meta Title SEO Ramah CTR",
  "meta_description": "Meta Description persuasif dengan CTA",
  "focus_keyword": "{$keyword}",
  "image_keyword": "{$keyword} professional strategy",
  "image_prompt": "A realistic modern editorial photograph of...",
  "key_takeaways": [
    "Jawaban inti dan esensi utama topik secara ringkas",
    "Fakta atau data krusial yang relevan",
    "Langkah rekomendasi atau solusi praktis"
  ],
  "content": "<blockquote><p><strong>💡 Key Takeaways (Poin Kunci):</strong></p><ul><li>...</li><li>...</li><li>...</li></ul></blockquote><p>Penjelasan pembuka...</p><h2>Apa itu ...?</h2><p>Jawaban langsung...</p><h2>Bagaimana Cara ...?</h2><p>Langkah detail...</p>",
  "faqs": [
    {
      "question": "Pertanyaan 1?",
      "answer": "Jawaban 1..."
    }
  ]
}
EOT;

        return $prompt;
    }

    /**
     * Generate content via Google Gemini API
     */
    /**
     * Generate content via Google Gemini API
     */
    private function generate_with_gemini($row, $settings) {
        $api_key = isset($settings['gemini_api_key']) ? trim($settings['gemini_api_key']) : '';
        if (empty($api_key)) {
            return new WP_Error('no_gemini_key', __('Google Gemini API Key belum diisi di pengaturan.', 'synka-auto-seo'));
        }

        $available_models = $this->get_gemini_models($api_key);
        $prompt = $this->build_prompt($row, $settings);

        $body_payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature'        => 0.7,
                'responseMimeType'   => 'application/json',
            ]
        ];

        $last_error = '';

        foreach ($available_models as $item) {
            $version = isset($item['version']) ? $item['version'] : 'v1beta';
            $model   = isset($item['model']) ? $item['model'] : (is_string($item) ? $item : 'gemini-1.5-flash');

            $url = "https://generativelanguage.googleapis.com/{$version}/models/{$model}:generateContent?key=" . urlencode($api_key);

            $response = wp_remote_post($url, [
                'timeout'   => 50,
                'sslverify' => false,
                'headers'   => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $api_key,
                ],
                'body'      => wp_json_encode($body_payload),
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // If responseMimeType is not supported by this model (400), retry without it
            if ($code === 400 && strpos($body, 'responseMimeType') !== false) {
                unset($body_payload['generationConfig']['responseMimeType']);
                $response = wp_remote_post($url, [
                    'timeout'   => 50,
                    'sslverify' => false,
                    'headers'   => [
                        'Content-Type'   => 'application/json',
                        'x-goog-api-key' => $api_key,
                    ],
                    'body'      => wp_json_encode($body_payload),
                ]);
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
            }

            if ($code === 200) {
                $json = json_decode($body, true);
                $text_response = isset($json['candidates'][0]['content']['parts'][0]['text']) 
                    ? $json['candidates'][0]['content']['parts'][0]['text'] 
                    : '';

                if (!empty($text_response)) {
                    // Remember this working model at the top of cache
                    $this->save_working_gemini_model($item, $available_models);
                    return $this->parse_ai_json($text_response, $row);
                }
            } else {
                $err_data = json_decode($body, true);
                $last_error = isset($err_data['error']['message']) ? $err_data['error']['message'] : ("HTTP " . $code);
                
                // If model not found (404), incompatible (400), overloaded (503), or quota (429), try next model in list
                if (in_array($code, [400, 404, 429, 500, 502, 503, 504])) {
                    continue;
                } else {
                    return new WP_Error('gemini_error', "Gemini API Error (HTTP {$code}): " . $last_error);
                }
            }
        }

        // If all failed, delete cache so next run rediscovers models freshly
        delete_transient('synka_gemini_models_v3');

        return new WP_Error('gemini_error', "Gagal menghubungi Gemini API. Detail: " . $last_error);
    }

    /**
     * Discover and cache available Gemini models for this specific API Key
     */
    private function get_gemini_models($api_key) {
        $cached = get_transient('synka_gemini_models_v3');
        if (!empty($cached) && is_array($cached)) {
            return $cached;
        }

        $discovered = [];

        // Check both v1beta and v1 endpoints
        foreach (['v1beta', 'v1'] as $ver) {
            $list_url = "https://generativelanguage.googleapis.com/{$ver}/models?key=" . urlencode($api_key);
            $res = wp_remote_get($list_url, [
                'timeout'   => 15,
                'sslverify' => false,
                'headers'   => [
                    'x-goog-api-key' => $api_key,
                ]
            ]);

            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (!empty($data['models']) && is_array($data['models'])) {
                    foreach ($data['models'] as $m) {
                        $methods = isset($m['supportedGenerationMethods']) ? $m['supportedGenerationMethods'] : [];
                        if (in_array('generateContent', $methods)) {
                            $m_name = str_replace('models/', '', $m['name']);
                            $is_flash = (strpos($m_name, 'flash') !== false);
                            $discovered[] = [
                                'version'  => $ver,
                                'model'    => $m_name,
                                'is_flash' => $is_flash,
                            ];
                        }
                    }
                }
            }
            if (!empty($discovered)) {
                break;
            }
        }

        if (!empty($discovered)) {
            // Sort so flash models are tried first
            usort($discovered, function($a, $b) {
                return ($b['is_flash'] ? 1 : 0) - ($a['is_flash'] ? 1 : 0);
            });
            set_transient('synka_gemini_models_v3', $discovered, 7 * DAY_IN_SECONDS);
            return $discovered;
        }

        // Default fallbacks
        return [
            ['version' => 'v1beta', 'model' => 'gemini-1.5-flash', 'is_flash' => true],
            ['version' => 'v1beta', 'model' => 'gemini-1.5-flash-latest', 'is_flash' => true],
            ['version' => 'v1beta', 'model' => 'gemini-2.0-flash', 'is_flash' => true],
            ['version' => 'v1beta', 'model' => 'gemini-2.0-flash-exp', 'is_flash' => true],
            ['version' => 'v1',     'model' => 'gemini-1.5-flash', 'is_flash' => true],
            ['version' => 'v1beta', 'model' => 'gemini-1.5-pro', 'is_flash' => false],
            ['version' => 'v1',     'model' => 'gemini-pro', 'is_flash' => false],
        ];
    }

    /**
     * Prioritize successful model for future instant generations
     */
    private function save_working_gemini_model($working_item, $all_models) {
        $reordered = [$working_item];
        foreach ($all_models as $item) {
            if ($item['model'] !== $working_item['model'] || $item['version'] !== $working_item['version']) {
                $reordered[] = $item;
            }
        }
        set_transient('synka_gemini_models_v3', $reordered, 7 * DAY_IN_SECONDS);
    }

    /**
     * Generate content via OpenAI API
     */
    private function generate_with_openai($row, $settings) {
        $api_key = isset($settings['openai_api_key']) ? trim($settings['openai_api_key']) : '';
        $model = isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-4o-mini';

        if (empty($api_key)) {
            return new WP_Error('no_openai_key', __('OpenAI API Key belum diisi di pengaturan.', 'synka-auto-seo'));
        }

        $prompt = $this->build_prompt($row, $settings);
        $url = "https://api.openai.com/v1/chat/completions";

        $body_payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an elite SEO copywriter and expert in Google ranking algorithms. You always return raw valid JSON conforming to the requested schema.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 90,
            'sslverify' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($body_payload),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err_data = json_decode($body, true);
            $err_msg = isset($err_data['error']['message']) ? $err_data['error']['message'] : $body;
            return new WP_Error('openai_error', "OpenAI API Error (HTTP {$code}): " . $err_msg);
        }

        $json = json_decode($body, true);
        $text_response = isset($json['choices'][0]['message']['content']) ? $json['choices'][0]['message']['content'] : '';

        if (empty($text_response)) {
            return new WP_Error('empty_openai_response', __('Respon konten dari OpenAI kosong.', 'synka-auto-seo'));
        }

        return $this->parse_ai_json($text_response, $row);
    }

    /**
     * Clean and parse AI JSON output safely with multi-stage recovery
     */
    private function parse_ai_json($raw_text, $row) {
        $clean = trim($raw_text);
        
        // 1. Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        // 2. Stage 1: Try native json_decode directly
        $parsed = json_decode($clean, true);

        // 3. Stage 2: Try fixing unescaped newlines inside JSON string values
        if (!is_array($parsed) || empty($parsed['content'])) {
            // Replace literal newlines inside JSON string values with \n
            $repaired = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function($m) {
                return str_replace(["\r\n", "\r", "\n"], '\n', $m[0]);
            }, $clean);
            $parsed = json_decode($repaired, true);
        }

        // 4. Stage 3: Regex Field Extraction Fallback if JSON decoding still fails
        if (!is_array($parsed) || empty($parsed['content'])) {
            $parsed = $this->extract_fields_with_regex($clean, $row);
        }

        $default_title = isset($row['keyword']) ? ucwords($row['keyword']) : 'Artikel Terbaru';
        $title = !empty($parsed['title']) ? trim($parsed['title']) : $default_title;
        $content = !empty($parsed['content']) ? trim($parsed['content']) : '';

        // Clean any leftover JSON artifacts from content
        $content = $this->clean_content_html($content);

        // Ensure key_takeaways is an array of strings if provided
        $key_takeaways = [];
        if (!empty($parsed['key_takeaways']) && is_array($parsed['key_takeaways'])) {
            foreach ($parsed['key_takeaways'] as $kt) {
                if (is_string($kt) && trim($kt) !== '') {
                    $key_takeaways[] = trim($kt);
                }
            }
        }

        return [
            'title'            => $title,
            'content'          => $content,
            'key_takeaways'    => $key_takeaways,
            'meta_title'       => !empty($parsed['meta_title']) ? trim($parsed['meta_title']) : $title,
            'meta_description' => !empty($parsed['meta_description']) ? trim($parsed['meta_description']) : '',
            'focus_keyword'    => !empty($parsed['focus_keyword']) ? trim($parsed['focus_keyword']) : (isset($row['keyword']) ? $row['keyword'] : ''),
            'image_keyword'    => !empty($parsed['image_keyword']) ? trim($parsed['image_keyword']) : (isset($row['keyword']) ? $row['keyword'] : 'business technology'),
            'image_prompt'     => !empty($parsed['image_prompt']) ? trim($parsed['image_prompt']) : '',
            'faqs'             => !empty($parsed['faqs']) && is_array($parsed['faqs']) ? $parsed['faqs'] : [],
        ];
    }

    /**
     * Regex extractor to safely pull fields when AI output is malformed JSON
     */
    private function extract_fields_with_regex($text, $row) {
        $extracted = [];

        // Title
        if (preg_match('/"title"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['title'] = stripslashes($m[1]);
        }

        // Meta Title
        if (preg_match('/"meta_title"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['meta_title'] = stripslashes($m[1]);
        }

        // Meta Description
        if (preg_match('/"meta_description"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['meta_description'] = stripslashes($m[1]);
        }

        // Focus Keyword
        if (preg_match('/"focus_keyword"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['focus_keyword'] = stripslashes($m[1]);
        }

        // Image Keyword
        if (preg_match('/"image_keyword"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['image_keyword'] = stripslashes($m[1]);
        }

        // Image Prompt
        if (preg_match('/"image_prompt"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $extracted['image_prompt'] = stripslashes($m[1]);
        }

        // Key Takeaways Extraction
        if (preg_match('/"key_takeaways"\s*:\s*(\[\s*[\s\S]*?\s*\])/i', $text, $m)) {
            $kt = json_decode($m[1], true);
            if (is_array($kt)) {
                $extracted['key_takeaways'] = $kt;
            }
        }

        // Content
        if (preg_match('/"content"\s*:\s*"([\s\S]*?)"\s*(?:,\s*"(?:faqs|title|meta_title|meta_description|focus_keyword|image_keyword|image_prompt|key_takeaways)"|\s*\}\s*$)/i', $text, $m)) {
            $extracted['content'] = stripslashes($m[1]);
        } elseif (preg_match('/"content"\s*:\s*`([\s\S]*?)`/i', $text, $m)) {
            $extracted['content'] = stripslashes($m[1]);
        } else {
            // If no content key matched, strip outer JSON structure and keep HTML
            $clean_html = preg_replace('/^\s*\{\s*"title"[\s\S]*?"content"\s*:\s*"?/i', '', $text);
            $clean_html = preg_replace('/"?,?\s*"(?:faqs|key_takeaways)"[\s\S]*$/i', '', $clean_html);
            $clean_html = preg_replace('/\s*\}\s*$/', '', $clean_html);
            $extracted['content'] = stripslashes($clean_html);
        }

        // FAQs Extraction
        if (preg_match('/"faqs"\s*:\s*(\[\s*\{[\s\S]*?\}\s*\])/i', $text, $m)) {
            $faqs = json_decode($m[1], true);
            if (is_array($faqs)) {
                $extracted['faqs'] = $faqs;
            }
        }

        return $extracted;
    }

    /**
     * Clean and normalize article HTML content
     */
    private function clean_content_html($content) {
        // Strip escaped newlines if literal \n remained
        $content = str_replace(['\n', '\r', '\t'], ["\n", '', ' '], $content);

        // Strip accidental enclosing quotes
        $content = trim($content);
        if (substr($content, 0, 1) === '"' && substr($content, -1) === '"') {
            $content = substr($content, 1, -1);
        }

        // If content starts with JSON bracket accidentally, extract HTML inside
        if (strpos($content, '{') === 0 && preg_match('/<p|<h2|<h3/i', $content)) {
            if (preg_match('/(<(?:p|h2|h3|ul|ol|table|div)[\s\S]+)/i', $content, $m)) {
                $content = $m[1];
                $content = preg_replace('/"\s*,\s*"faqs"[\s\S]*$/i', '', $content);
                $content = preg_replace('/"\s*\}\s*$/', '', $content);
            }
        }

        return trim($content);
    }
}
