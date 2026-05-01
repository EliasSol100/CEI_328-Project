<?php

require_once __DIR__ . '/platform_integrations.php';

if (!function_exists('app_custom_order_default_steps')) {
    function app_custom_order_default_steps(): array
    {
        return [
            'instagram' => [
                [
                    'title_en' => 'Open the Instagram chat',
                    'text_en' => 'Use the Message on Instagram button, or open the shop profile and start a direct message.',
                    'title_gr' => 'Άνοιξε το Instagram chat',
                    'text_gr' => 'Χρησιμοποίησε το κουμπί Message on Instagram ή άνοιξε το προφίλ του shop και στείλε direct message.',
                ],
                [
                    'title_en' => 'Send your idea and references',
                    'text_en' => 'Tell Athina what you want, who it is for, and attach any inspiration photos, colors, or size examples.',
                    'title_gr' => 'Στείλε την ιδέα και τα references',
                    'text_gr' => 'Πες στην Athina τι θέλεις, για ποιον είναι, και στείλε φωτογραφίες, χρώματα ή παραδείγματα μεγέθους.',
                ],
                [
                    'title_en' => 'Discuss details in chat',
                    'text_en' => 'Agree on yarn, colors, size, deadline, small changes, and anything that affects the final price.',
                    'title_gr' => 'Συζητήστε τις λεπτομέρειες στο chat',
                    'text_gr' => 'Συμφωνήστε νήμα, χρώματα, μέγεθος, deadline, μικρές αλλαγές και ό,τι επηρεάζει την τελική τιμή.',
                ],
                [
                    'title_en' => 'Confirm the final offer',
                    'text_en' => 'When both sides agree, Athina prepares a private checkout product for your custom order.',
                    'title_gr' => 'Επιβεβαίωσε το final offer',
                    'text_gr' => 'Όταν συμφωνήσουν και οι δύο πλευρές, η Athina ετοιμάζει private checkout product για το custom order.',
                ],
                [
                    'title_en' => 'Pay through the private link',
                    'text_en' => 'Open the private shop link while signed in with your account, then complete checkout on the website.',
                    'title_gr' => 'Πλήρωσε μέσω private link',
                    'text_gr' => 'Άνοιξε το private shop link ενώ είσαι signed in με τον λογαριασμό σου και ολοκλήρωσε το checkout στο website.',
                ],
            ],
            'website' => [
                [
                    'title_en' => 'Sign in or create an account',
                    'text_en' => 'A registered account is required so replies, offers, and private checkout links stay connected to you.',
                    'title_gr' => 'Κάνε sign in ή δημιούργησε account',
                    'text_gr' => 'Χρειάζεται registered account ώστε replies, offers και private checkout links να μένουν συνδεδεμένα με εσένα.',
                ],
                [
                    'title_en' => 'Complete and verify your profile',
                    'text_en' => 'Make sure your profile details and email verification are finished before sending the request.',
                    'title_gr' => 'Ολοκλήρωσε και κάνε verify το profile',
                    'text_gr' => 'Βεβαιώσου ότι τα profile details και το email verification έχουν ολοκληρωθεί πριν στείλεις request.',
                ],
                [
                    'title_en' => 'Fill in the request form',
                    'text_en' => 'Add the idea title, product type, preferred size, colors, budget, deadline, and a clear description.',
                    'title_gr' => 'Συμπλήρωσε το request form',
                    'text_gr' => 'Πρόσθεσε idea title, product type, preferred size, colors, budget, deadline και καθαρή περιγραφή.',
                ],
                [
                    'title_en' => 'Attach a reference photo if needed',
                    'text_en' => 'Photos are optional, but they help explain shapes, colors, characters, or styles more clearly.',
                    'title_gr' => 'Πρόσθεσε reference photo αν χρειάζεται',
                    'text_gr' => 'Οι φωτογραφίες είναι optional, αλλά βοηθούν να εξηγηθούν καλύτερα shapes, colors, characters ή styles.',
                ],
                [
                    'title_en' => "Wait for Athina's reply",
                    'text_en' => 'Athina can ask for more details, make an offer, accept the idea, or decline it if it cannot be made.',
                    'title_gr' => 'Περίμενε reply από την Athina',
                    'text_gr' => 'Η Athina μπορεί να ζητήσει περισσότερες λεπτομέρειες, να κάνει offer, να αποδεχτεί την ιδέα ή να την απορρίψει αν δεν μπορεί να γίνει.',
                ],
                [
                    'title_en' => 'Accept the offer and checkout',
                    'text_en' => 'If the offer works for you, accept it and use the private checkout link sent to your account.',
                    'title_gr' => 'Αποδέξου το offer και κάνε checkout',
                    'text_gr' => 'Αν το offer σε καλύπτει, αποδέξου το και χρησιμοποίησε το private checkout link που στάλθηκε στον λογαριασμό σου.',
                ],
            ],
        ];
    }
}

if (!function_exists('app_custom_order_normalize_steps')) {
    function app_custom_order_normalize_steps(array $input): array
    {
        $defaults = app_custom_order_default_steps();
        $normalized = [];
        $limitText = static function (string $value, int $length): string {
            return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
        };

        foreach ($defaults as $path => $defaultSteps) {
            $normalized[$path] = [];
            $sourceSteps = is_array($input[$path] ?? null) ? $input[$path] : [];
            if (empty($sourceSteps) && !array_key_exists($path, $input)) {
                $sourceSteps = $defaultSteps;
            }

            foreach (array_values($sourceSteps) as $idx => $sourceStep) {
                if (!is_array($sourceStep)) {
                    continue;
                }
                $defaultStep = $defaultSteps[$idx] ?? [];
                $source = $sourceStep;
                $titleEn = trim((string)($source['title_en'] ?? $sourceStep['title_en'] ?? ''));
                $textEn = trim((string)($source['text_en'] ?? $sourceStep['text_en'] ?? ''));
                $titleGr = trim((string)($source['title_gr'] ?? $sourceStep['title_gr'] ?? ''));
                $textGr = trim((string)($source['text_gr'] ?? $sourceStep['text_gr'] ?? ''));

                if ($titleEn === '') {
                    $titleEn = trim((string)($defaultStep['title_en'] ?? ''));
                }
                if ($textEn === '') {
                    $textEn = trim((string)($defaultStep['text_en'] ?? ''));
                }
                if ($titleGr === '') {
                    $titleGr = trim((string)($defaultStep['title_gr'] ?? $titleEn));
                }
                if ($textGr === '') {
                    $textGr = trim((string)($defaultStep['text_gr'] ?? $textEn));
                }

                if ($titleEn === '' && $textEn === '' && $titleGr === '' && $textGr === '') {
                    continue;
                }

                $normalized[$path][] = [
                    'title_en' => $titleEn !== '' ? $limitText($titleEn, 160) : ('Step ' . ((int)$idx + 1)),
                    'text_en' => $limitText($textEn, 600),
                    'title_gr' => $limitText($titleGr !== '' ? $titleGr : $titleEn, 160),
                    'text_gr' => $limitText($textGr !== '' ? $textGr : $textEn, 600),
                ];
            }
        }

        return $normalized;
    }
}

if (!function_exists('app_custom_order_steps')) {
    function app_custom_order_steps(mysqli $conn): array
    {
        $defaults = app_custom_order_default_steps();
        app_system_config_seed_defaults($conn, [
            'custom_order_steps_json' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = app_system_config_get($conn, 'custom_order_steps_json', '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return app_custom_order_normalize_steps(is_array($decoded) ? $decoded : $defaults);
    }
}

if (!function_exists('app_custom_order_save_steps')) {
    function app_custom_order_save_steps(mysqli $conn, array $input): bool
    {
        $steps = app_custom_order_normalize_steps($input);
        return app_system_config_set($conn, 'custom_order_steps_json', json_encode($steps, JSON_UNESCAPED_UNICODE));
    }
}
