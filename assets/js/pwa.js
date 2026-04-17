(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    function isLocalhost(hostname) {
        return hostname === 'localhost' ||
            hostname === '127.0.0.1' ||
            hostname === '[::1]';
    }

    if (window.location.protocol !== 'https:' && !isLocalhost(window.location.hostname)) {
        return;
    }

    window.addEventListener('load', function () {
        var config = window.athinaPwa || {};
        var serviceWorkerUrl = config.serviceWorkerUrl || 'sw.js';

        navigator.serviceWorker.register(serviceWorkerUrl).catch(function (error) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('PWA service worker registration failed:', error);
            }
        });
    });
})();
