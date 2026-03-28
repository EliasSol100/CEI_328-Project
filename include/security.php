<?php

if (!function_exists('app_ensure_session_started')) {
    function app_ensure_session_started(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('app_h')) {
    function app_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('app_csrf_token')) {
    function app_csrf_token(): string
    {
        app_ensure_session_started();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('app_request_csrf_token')) {
    function app_request_csrf_token(): string
    {
        app_ensure_session_started();
        $headerToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return $headerToken;
        }
        return trim((string)($_POST['csrf_token'] ?? ''));
    }
}

if (!function_exists('app_verify_csrf')) {
    function app_verify_csrf(?string $token = null): bool
    {
        app_ensure_session_started();
        $expected = (string)($_SESSION['csrf_token'] ?? '');
        $provided = $token !== null ? trim($token) : app_request_csrf_token();
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}

if (!function_exists('app_require_csrf')) {
    function app_require_csrf(bool $json = false, string $message = 'Invalid CSRF token'): void
    {
        if (app_verify_csrf()) {
            return;
        }

        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'ok' => false,
                'message' => $message,
                'error' => $message,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo app_h($message);
        }
        exit;
    }
}

if (!function_exists('app_csrf_input')) {
    function app_csrf_input(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . app_h(app_csrf_token()) . '">';
    }
}

if (!function_exists('app_csrf_bootstrap_script')) {
    function app_csrf_bootstrap_script(): string
    {
        $token = app_csrf_token();
        $jsonToken = json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        return <<<HTML
<script>
(function () {
    window.APP_CSRF_TOKEN = {$jsonToken};

    function injectCsrfIntoPostForms(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form').forEach(function (form) {
            var method = String(form.getAttribute('method') || 'get').toLowerCase();
            if (method !== 'post') return;
            if (form.querySelector('input[name="csrf_token"]')) return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = window.APP_CSRF_TOKEN;
            form.appendChild(input);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            injectCsrfIntoPostForms(document);
        });
    } else {
        injectCsrfIntoPostForms(document);
    }

    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) return;
                    if (node.tagName && node.tagName.toLowerCase() === 'form') {
                        injectCsrfIntoPostForms(node.parentNode || document);
                        return;
                    }
                    injectCsrfIntoPostForms(node);
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
})();
</script>
HTML;
    }
}

if (!function_exists('app_oauth_state')) {
    function app_oauth_state(string $provider): string
    {
        app_ensure_session_started();
        $provider = preg_replace('/[^a-z0-9_]/i', '', strtolower($provider));
        $key = 'oauth_state_' . $provider;
        $_SESSION[$key] = bin2hex(random_bytes(32));
        return $_SESSION[$key];
    }
}

if (!function_exists('app_verify_oauth_state')) {
    function app_verify_oauth_state(string $provider, ?string $state): bool
    {
        app_ensure_session_started();
        $provider = preg_replace('/[^a-z0-9_]/i', '', strtolower($provider));
        $key = 'oauth_state_' . $provider;
        $expected = (string)($_SESSION[$key] ?? '');
        unset($_SESSION[$key]);
        $provided = trim((string)$state);
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}

if (!function_exists('app_allowed_image_mime')) {
    function app_allowed_image_mime(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }
}