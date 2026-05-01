<?php

require_once __DIR__ . '/platform_integrations.php';

if (!function_exists('app_website_content_defaults')) {
    function app_website_content_defaults(): array
    {
        return [
            'home' => [
                'hero' => [
                    'title_en' => 'Soft Handmade Crochet Treasures',
                    'title_gr' => 'Απαλές Χειροποίητες Crochet Δημιουργίες',
                    'subtitle_en' => 'Discover cozy plushies, thoughtful gifts, and charming crochet creations made with love by Athina.',
                    'subtitle_gr' => 'Ανακάλυψε cozy λούτρινα, ξεχωριστά δώρα και όμορφες crochet δημιουργίες φτιαγμένες με αγάπη από την Athina.',
                    'button_en' => 'Shop Now',
                    'button_gr' => 'Αγόρασε Τώρα',
                    'button_url' => 'shop.php',
                ],
            ],
            'about' => [
                'story' => [
                    'title_en' => 'Our Story',
                    'title_gr' => 'Η Ιστορία Μας',
                    'content_en' => "Creations by Athina was born out of a deep passion for crochet and the joy of creating something beautiful with your own hands.\n\nEvery item in our shop is carefully crafted with love, attention to detail, and the finest quality yarns. No two pieces are exactly alike, and that is the beauty of handmade.",
                    'content_gr' => "Το Creations by Athina δημιουργήθηκε από την αγάπη για το crochet και τη χαρά του handmade.\n\nΚάθε προϊόν φτιάχνεται με φροντίδα, προσοχή στη λεπτομέρεια και ποιοτικά νήματα. Κανένα κομμάτι δεν είναι ακριβώς ίδιο με το άλλο, και εκεί βρίσκεται η ομορφιά του handmade.",
                ],
                'values' => [
                    'title_en' => 'Our Values',
                    'title_gr' => 'Οι Αξίες Μας',
                    'items' => [
                        [
                            'title_en' => 'Handmade Quality',
                            'title_gr' => 'Χειροποίητη Ποιότητα',
                            'text_en' => 'Each item is carefully crafted by hand with attention to detail',
                            'text_gr' => 'Κάθε αντικείμενο είναι προσεκτικά φτιαγμένο στο χέρι με προσοχή στη λεπτομέρεια',
                        ],
                        [
                            'title_en' => 'Perfect Gifts',
                            'title_gr' => 'Ιδανικά Δώρα',
                            'text_en' => 'Unique presents that show you care, with gift wrapping available',
                            'text_gr' => 'Μοναδικά δώρα που δείχνουν φροντίδα, με διαθέσιμο gift wrapping',
                        ],
                        [
                            'title_en' => 'Eco-Friendly',
                            'title_gr' => 'Φιλικό προς το Περιβάλλον',
                            'text_en' => 'Made with sustainable and high-quality materials',
                            'text_gr' => 'Φτιαγμένα με βιώσιμα και υψηλής ποιότητας υλικά',
                        ],
                    ],
                ],
            ],
            'contact' => [
                'email' => [
                    'label_en' => 'Email',
                    'label_gr' => 'Email',
                    'value' => 'creationsbyathina@gmail.com',
                ],
                'instagram' => [
                    'label_en' => 'Instagram',
                    'label_gr' => 'Instagram',
                    'value' => '@creations.by.athina',
                    'url' => 'https://www.instagram.com/creations.by.athina/',
                ],
                'facebook' => [
                    'label_en' => 'Facebook',
                    'label_gr' => 'Facebook',
                    'value' => 'Creations by Athina',
                    'url' => 'https://www.facebook.com/p/Creations-by-Athina-61555871434054/',
                ],
                'response_time' => [
                    'label_en' => 'Response Time',
                    'label_gr' => 'Χρόνος Απόκρισης',
                    'text_en' => 'We typically reply within 24-48 hours.',
                    'text_gr' => 'Συνήθως απαντάμε μέσα σε 24-48 ώρες.',
                ],
            ],
        ];
    }
}

if (!function_exists('app_website_content_text_value')) {
    function app_website_content_text_value(array $source, string $key, string $default): string
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        return trim((string)$source[$key]);
    }
}

if (!function_exists('app_website_content_legacy_page_pair')) {
    function app_website_content_legacy_page_pair(mysqli $conn, string $slug): array
    {
        $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'content_pages'");
        if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT pageTitle, language, content
             FROM content_pages
             WHERE slug = ? AND language IN ('en', 'gr', 'el') AND isPublished = 1"
        );
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 's', $slug);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $pages = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $lang = strtolower((string)($row['language'] ?? 'en'));
                $lang = $lang === 'el' ? 'gr' : $lang;
                if (!in_array($lang, ['en', 'gr'], true)) {
                    continue;
                }
                $pages[$lang] = [
                    'title' => (string)($row['pageTitle'] ?? ''),
                    'content' => (string)($row['content'] ?? ''),
                ];
            }
        }
        mysqli_stmt_close($stmt);

        return $pages;
    }
}

if (!function_exists('app_website_content_seed_from_legacy_pages')) {
    function app_website_content_seed_from_legacy_pages(mysqli $conn, array $defaults): array
    {
        $about = app_website_content_legacy_page_pair($conn, 'about-us');
        if (!empty($about['en']['content'])) {
            $defaults['about']['story']['content_en'] = trim((string)$about['en']['content']);
        }
        if (!empty($about['gr']['content'])) {
            $defaults['about']['story']['content_gr'] = trim((string)$about['gr']['content']);
        }

        return $defaults;
    }
}

if (!function_exists('app_website_content_normalize')) {
    function app_website_content_normalize(array $input, array $defaults): array
    {
        $homeHeroInput = is_array($input['home']['hero'] ?? null) ? $input['home']['hero'] : [];
        foreach (['title_en', 'title_gr', 'subtitle_en', 'subtitle_gr', 'button_en', 'button_gr', 'button_url'] as $field) {
            $defaults['home']['hero'][$field] = app_website_content_text_value(
                $homeHeroInput,
                $field,
                (string)$defaults['home']['hero'][$field]
            );
        }
        if ($defaults['home']['hero']['button_url'] === '') {
            $defaults['home']['hero']['button_url'] = 'shop.php';
        }

        $aboutStoryInput = is_array($input['about']['story'] ?? null) ? $input['about']['story'] : [];
        foreach (['title_en', 'title_gr', 'content_en', 'content_gr'] as $field) {
            $defaults['about']['story'][$field] = app_website_content_text_value(
                $aboutStoryInput,
                $field,
                (string)$defaults['about']['story'][$field]
            );
        }

        $aboutValuesInput = is_array($input['about']['values'] ?? null) ? $input['about']['values'] : [];
        foreach (['title_en', 'title_gr'] as $field) {
            $defaults['about']['values'][$field] = app_website_content_text_value(
                $aboutValuesInput,
                $field,
                (string)$defaults['about']['values'][$field]
            );
        }
        $itemsInput = is_array($aboutValuesInput['items'] ?? null) ? $aboutValuesInput['items'] : [];
        foreach ($defaults['about']['values']['items'] as $idx => $defaultItem) {
            $itemInput = is_array($itemsInput[$idx] ?? null) ? $itemsInput[$idx] : [];
            foreach (['title_en', 'title_gr', 'text_en', 'text_gr'] as $field) {
                $defaults['about']['values']['items'][$idx][$field] = app_website_content_text_value(
                    $itemInput,
                    $field,
                    (string)$defaultItem[$field]
                );
            }
        }

        foreach (['email', 'instagram', 'facebook'] as $boxKey) {
            $boxInput = is_array($input['contact'][$boxKey] ?? null) ? $input['contact'][$boxKey] : [];
            foreach (['label_en', 'label_gr', 'value', 'url'] as $field) {
                if (!array_key_exists($field, $defaults['contact'][$boxKey]) && !array_key_exists($field, $boxInput)) {
                    continue;
                }
                $defaults['contact'][$boxKey][$field] = app_website_content_text_value(
                    $boxInput,
                    $field,
                    (string)($defaults['contact'][$boxKey][$field] ?? '')
                );
            }
        }

        $responseInput = is_array($input['contact']['response_time'] ?? null) ? $input['contact']['response_time'] : [];
        foreach (['label_en', 'label_gr', 'text_en', 'text_gr'] as $field) {
            $defaults['contact']['response_time'][$field] = app_website_content_text_value(
                $responseInput,
                $field,
                (string)$defaults['contact']['response_time'][$field]
            );
        }

        return $defaults;
    }
}

if (!function_exists('app_website_content_settings')) {
    function app_website_content_settings(mysqli $conn): array
    {
        $defaults = app_website_content_seed_from_legacy_pages($conn, app_website_content_defaults());
        app_system_config_seed_defaults($conn, [
            'website_content_json' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = app_system_config_get($conn, 'website_content_json', '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return app_website_content_normalize(is_array($decoded) ? $decoded : [], $defaults);
    }
}

if (!function_exists('app_website_content_save')) {
    function app_website_content_save(mysqli $conn, array $input): bool
    {
        $settings = app_website_content_normalize($input, app_website_content_defaults());
        return app_system_config_set($conn, 'website_content_json', json_encode($settings, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('app_website_content_multiline_html')) {
    function app_website_content_multiline_html(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $paragraphs = preg_split("/\R{2,}/", $content) ?: [$content];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html[] = '<p>' . nl2br(app_h($paragraph), false) . '</p>';
        }

        return implode("\n", $html);
    }
}

if (!function_exists('app_website_content_safe_href')) {
    function app_website_content_safe_href(string $href, string $fallback = '#'): string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^\s*javascript:/i', $href)) {
            return $fallback;
        }

        return $href;
    }
}
