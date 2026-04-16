(function () {
    var COOKIE_NAME = "athina_cookie_consent";
    var COOKIE_MAX_AGE_DAYS = 180;
    var FOCUSABLE_SELECTOR = [
        "a[href]",
        "button:not([disabled])",
        "input:not([disabled])",
        "select:not([disabled])",
        "textarea:not([disabled])",
        "[tabindex]:not([tabindex='-1'])"
    ].join(",");

    function safeJsonParse(value) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function readCookie(name) {
        var encodedName = encodeURIComponent(name) + "=";
        var parts = document.cookie ? document.cookie.split("; ") : [];

        for (var i = 0; i < parts.length; i += 1) {
            if (parts[i].indexOf(encodedName) === 0) {
                return decodeURIComponent(parts[i].slice(encodedName.length));
            }
        }

        return "";
    }

    function normalizeConsent(parsed) {
        if (!parsed || typeof parsed !== "object") {
            return null;
        }

        return {
            necessary: true,
            preferences: Boolean(parsed.preferences),
            analytics: Boolean(parsed.analytics),
            choice: typeof parsed.choice === "string" ? parsed.choice : "custom",
            updatedAt: typeof parsed.updatedAt === "string" ? parsed.updatedAt : ""
        };
    }

    function getStoredConsent() {
        return normalizeConsent(safeJsonParse(readCookie(COOKIE_NAME)));
    }

    function writeCookie(name, value, days) {
        var expiresAt = new Date();
        expiresAt.setTime(expiresAt.getTime() + (days * 24 * 60 * 60 * 1000));

        var cookie = [
            encodeURIComponent(name) + "=" + encodeURIComponent(value),
            "expires=" + expiresAt.toUTCString(),
            "path=/",
            "SameSite=Lax"
        ];

        if (window.location.protocol === "https:") {
            cookie.push("Secure");
        }

        document.cookie = cookie.join("; ");
    }

    function currentLang() {
        return document.documentElement.lang === "el" ? "el" : "en";
    }

    function setElementText(element, value) {
        if (element) {
            element.textContent = value;
        }
    }

    function getButtonLabel(button, type) {
        if (!button) {
            return "";
        }

        var suffix = currentLang();
        var attr = type === "save" ? "saveLabel" : "customizeLabel";
        return button.dataset[attr + suffix.charAt(0).toUpperCase() + suffix.slice(1)] || button.textContent;
    }

    function removePreferenceStorage() {
        try {
            window.localStorage.removeItem("language");
        } catch (error) {
            // Ignore localStorage access issues silently.
        }
    }

    var shell = document.querySelector("[data-cookie-consent]");
    if (!shell) {
        return;
    }

    var dialog = shell.querySelector(".cookie-consent-dialog");
    var customizePanel = shell.querySelector("[data-cookie-customize]");
    var customizeButton = shell.querySelector("[data-consent-action='customize']");
    var closeButton = shell.querySelector("[data-cookie-close]");
    var triggerButtons = document.querySelectorAll("[data-cookie-preferences-trigger]");
    var activeTrigger = null;
    var manualOpen = false;
    var isCustomizeOpen = false;
    var consentState = getStoredConsent();

    function hasDecision() {
        return consentState !== null;
    }

    function allows(category) {
        if (category === "necessary") {
            return true;
        }

        return Boolean(consentState && consentState[category]);
    }

    function updateCustomizeButtonLabel() {
        if (!customizeButton) {
            return;
        }

        setElementText(customizeButton, getButtonLabel(customizeButton, isCustomizeOpen ? "save" : "customize"));
    }

    function syncCheckboxes(state) {
        var consent = state || {
            necessary: true,
            preferences: false,
            analytics: false
        };

        shell.querySelectorAll("[data-consent-category]").forEach(function (input) {
            var category = input.getAttribute("data-consent-category");
            if (category === "necessary") {
                input.checked = true;
                return;
            }

            input.checked = Boolean(consent[category]);
        });
    }

    function getCustomSelection() {
        var selection = {
            necessary: true,
            preferences: false,
            analytics: false,
            choice: "custom",
            updatedAt: new Date().toISOString()
        };

        shell.querySelectorAll("[data-consent-category]").forEach(function (input) {
            var category = input.getAttribute("data-consent-category");
            if (category === "necessary") {
                return;
            }

            selection[category] = Boolean(input.checked);
        });

        return selection;
    }

    function dispatchConsentChange() {
        document.dispatchEvent(new CustomEvent("app:cookieconsentchange", {
            detail: {
                consent: consentState,
                preferences: allows("preferences"),
                analytics: allows("analytics")
            }
        }));
    }

    function persistConsent(nextState) {
        consentState = {
            necessary: true,
            preferences: Boolean(nextState.preferences),
            analytics: Boolean(nextState.analytics),
            choice: nextState.choice || "custom",
            updatedAt: nextState.updatedAt || new Date().toISOString()
        };

        if (!consentState.preferences) {
            removePreferenceStorage();
        }

        writeCookie(COOKIE_NAME, JSON.stringify(consentState), COOKIE_MAX_AGE_DAYS);
        dispatchConsentChange();
    }

    function setCustomizeOpen(shouldOpen) {
        isCustomizeOpen = Boolean(shouldOpen);
        customizePanel.hidden = !isCustomizeOpen;
        shell.classList.toggle("cookie-consent-shell-customizing", isCustomizeOpen);
        updateCustomizeButtonLabel();
    }

    function getFocusableElements() {
        return Array.prototype.slice.call(dialog.querySelectorAll(FOCUSABLE_SELECTOR)).filter(function (element) {
            return !element.hidden && element.offsetParent !== null;
        });
    }

    function focusDialog() {
        window.requestAnimationFrame(function () {
            var focusable = getFocusableElements();
            if (focusable.length > 0) {
                focusable[0].focus();
                return;
            }

            dialog.focus();
        });
    }

    function setLockedState(isOpen) {
        document.documentElement.classList.toggle("cookie-consent-open", isOpen);
        if (document.body) {
            document.body.classList.toggle("cookie-consent-open", isOpen);
        }
    }

    function openModal(options) {
        var settings = options || {};

        manualOpen = Boolean(settings.manualOpen);
        activeTrigger = settings.trigger || null;

        shell.hidden = false;
        shell.classList.add("is-visible");
        shell.setAttribute("aria-hidden", "false");
        closeButton.hidden = !hasDecision();

        syncCheckboxes(consentState);
        setCustomizeOpen(Boolean(settings.forceCustomize) || (hasDecision() && consentState.choice === "custom"));
        setLockedState(true);
        focusDialog();
    }

    function closeModal() {
        shell.hidden = true;
        shell.classList.remove("is-visible");
        shell.setAttribute("aria-hidden", "true");
        setCustomizeOpen(false);
        setLockedState(false);

        if (manualOpen && activeTrigger && typeof activeTrigger.focus === "function") {
            activeTrigger.focus();
        }

        manualOpen = false;
        activeTrigger = null;
    }

    function saveConsent(state) {
        persistConsent(state);
        closeModal();
    }

    function handleCustomizeClick() {
        if (!isCustomizeOpen) {
            setCustomizeOpen(true);
            focusDialog();
            return;
        }

        saveConsent(getCustomSelection());
    }

    function handleKeydown(event) {
        if (shell.hidden) {
            return;
        }

        if (event.key === "Escape") {
            if (hasDecision() && manualOpen) {
                event.preventDefault();
                closeModal();
                return;
            }

            event.preventDefault();
            return;
        }

        if (event.key !== "Tab") {
            return;
        }

        var focusable = getFocusableElements();
        if (focusable.length === 0) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    dialog.addEventListener("click", function (event) {
        var actionTarget = event.target.closest("[data-consent-action]");
        if (!actionTarget) {
            return;
        }

        var action = actionTarget.getAttribute("data-consent-action");

        if (action === "accept-all") {
            saveConsent({
                necessary: true,
                preferences: true,
                analytics: true,
                choice: "all",
                updatedAt: new Date().toISOString()
            });
            return;
        }

        if (action === "accept-necessary") {
            saveConsent({
                necessary: true,
                preferences: false,
                analytics: false,
                choice: "necessary",
                updatedAt: new Date().toISOString()
            });
            return;
        }

        if (action === "customize") {
            handleCustomizeClick();
        }
    });

    if (closeButton) {
        closeButton.addEventListener("click", function () {
            if (hasDecision()) {
                closeModal();
            }
        });
    }

    document.addEventListener("keydown", handleKeydown);

    triggerButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            openModal({
                manualOpen: true,
                trigger: button,
                forceCustomize: true
            });
        });
    });

    shell.addEventListener("click", function (event) {
        if (event.target === shell || event.target.classList.contains("cookie-consent-backdrop")) {
            if (hasDecision() && manualOpen) {
                closeModal();
            }
        }
    });

    document.addEventListener("app:languagechange", updateCustomizeButtonLabel);

    window.appCookieConsent = {
        getState: function () {
            return consentState;
        },
        hasDecision: hasDecision,
        allows: allows,
        openPreferences: function () {
            openModal({
                manualOpen: true,
                forceCustomize: true
            });
        }
    };

    window.appCookieConsentAllows = allows;

    if (!hasDecision()) {
        openModal({
            manualOpen: false,
            forceCustomize: false
        });
    } else {
        shell.setAttribute("aria-hidden", "true");
        syncCheckboxes(consentState);
        updateCustomizeButtonLabel();
    }
})();
