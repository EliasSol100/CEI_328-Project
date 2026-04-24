<?php

require_once __DIR__ . '/translation_helpers.php';
require_once __DIR__ . '/security.php';

if (!function_exists('app_product_warning_messages')) {
    function app_product_warning_messages(): array
    {
        return [
            [
                'en' => 'Important: Small pieces and safety eyes on this plushie require parent supervision for babies and small children.',
                'el' => 'Σημαντικό: Τα μικρά κομμάτια και τα ματάκια σε αυτό το λούτρινο απαιτούν επίβλεψη από γονείς για μωρά και μικρά παιδιά.',
            ],
            [
                'en' => 'Disclaimer: Our listing photos are an approximate representation of the final color. Due to substrate differences, digital screen settings, and production variations, we cannot guarantee the color you see on your screen is the true color of the product.',
                'el' => 'Αποποίηση ευθύνης: Οι φωτογραφίες αποτελούν μια κατά προσέγγιση απεικόνιση του τελικού χρώματος. Λόγω διαφορών στα υλικά, στις ρυθμίσεις των ψηφιακών οθονών και στις παραλλαγές παραγωγής, δεν μπορούμε να εγγυηθούμε ότι το χρώμα που βλέπετε στην οθόνη σας είναι ακριβώς το πραγματικό χρώμα του προϊόντος.',
            ],
        ];
    }
}

if (!function_exists('app_product_normalize_description')) {
    function app_product_normalize_description(string $description): string
    {
        $description = str_replace(["\r\n", "\r"], "\n", $description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (app_product_warning_messages() as $warning) {
            foreach ([$warning['en'], $warning['el']] as $text) {
                $description = str_replace($text, '', $description);
                $description = str_replace(preg_replace('/^(Important|Disclaimer|Σημαντικό|Αποποίηση ευθύνης):\s*/u', '', $text), '', $description);
            }
        }

        $lines = array_map(static function (string $line): string {
            return trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
        }, explode("\n", $description));

        $description = implode("\n", $lines);
        $description = preg_replace("/\n{3,}/", "\n\n", $description) ?? $description;

        return trim($description);
    }
}

if (!function_exists('app_product_description_html')) {
    function app_product_description_html(string $description, string $fallback): string
    {
        $clean = app_product_normalize_description($description);
        if ($clean === '') {
            $clean = $fallback;
        }

        return nl2br(app_h($clean));
    }
}

if (!function_exists('app_product_warning_box_html')) {
    function app_product_warning_box_html(): string
    {
        $englishItems = [];
        $greekItems = [];
        foreach (app_product_warning_messages() as $warning) {
            $englishItems[] = '<li>' . app_h($warning['en']) . '</li>';
            $greekItems[] = '<li>' . app_h($warning['el']) . '</li>';
        }

        $english = '<div class="product-warning-title">Product warnings</div><ul>' . implode('', $englishItems) . '</ul>';
        $greek = '<div class="product-warning-title">Προειδοποιήσεις προϊόντος</div><ul>' . implode('', $greekItems) . '</ul>';

        return '<div class="product-warning-box"' . app_translate_html_attrs($english, $greek) . '>' . $english . '</div>';
    }
}
