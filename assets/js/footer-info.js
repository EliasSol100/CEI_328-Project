(function () {
    'use strict';

    function initFooterInfo() {
        var root = document.querySelector('[data-footer-info]');
        if (!root) {
            return;
        }

        var toggle = root.querySelector('.footer-info-toggle');
        var popup = root.querySelector('.footer-info-popup');
        var closeButton = root.querySelector('.footer-info-close');

        if (!toggle || !popup || !closeButton) {
            return;
        }

        function setOpen(isOpen) {
            root.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            popup.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(!root.classList.contains('is-open'));
        });

        closeButton.addEventListener('click', function () {
            setOpen(false);
            toggle.focus();
        });

        popup.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function (event) {
            if (root.classList.contains('is-open') && !root.contains(event.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFooterInfo);
    } else {
        initFooterInfo();
    }
})();
