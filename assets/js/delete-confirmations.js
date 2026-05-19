(function () {
    "use strict";

    var DEFAULT_MESSAGE = "Are you sure you want to delete or remove this item?";
    var DESTRUCTIVE_PATTERN = /(^|[\s_\-])(delete|remove|clear|dismiss|disconnect)([\s_\-]|$)/i;

    function isElement(value) {
        return value && value.nodeType === 1;
    }

    function confirmDelete(message) {
        return window.confirm(message || DEFAULT_MESSAGE);
    }

    function isSubmitControl(trigger) {
        if (!trigger || !trigger.closest("form") || !/^(BUTTON|INPUT)$/i.test(trigger.tagName || "")) {
            return false;
        }
        var type = String(trigger.getAttribute("type") || (trigger.tagName === "BUTTON" ? "submit" : "")).toLowerCase();
        return type === "submit" || type === "image";
    }

    function getSubmitter(event, form) {
        if (event.submitter && form.contains(event.submitter)) {
            return event.submitter;
        }
        var active = document.activeElement;
        if (active && form.contains(active) && /^(BUTTON|INPUT)$/i.test(active.tagName || "")) {
            return active;
        }
        return null;
    }

    function hasInlineConfirm(form, submitter) {
        var formHandler = form.getAttribute("onsubmit") || "";
        var submitterHandler = submitter ? (submitter.getAttribute("onclick") || "") : "";
        return /confirm\s*\(|confirmDelete\s*\(/i.test(formHandler + " " + submitterHandler);
    }

    function isIgnoredForm(form) {
        if (form.hasAttribute("data-skip-delete-confirmation")) {
            return true;
        }
        if (form.matches(".spr-delete-review-form")) {
            return true;
        }
        if (form.querySelector("button.wishlist-btn, button.shop-fav")) {
            return true;
        }
        return false;
    }

    function appendFormSignals(signals, form, submitter) {
        var fields = form.querySelectorAll("input, button, select, textarea");
        Array.prototype.forEach.call(fields, function (field) {
            if (field.disabled) {
                return;
            }
            var type = String(field.type || "").toLowerCase();
            if (type !== "hidden" && type !== "submit" && type !== "button") {
                return;
            }
            if ((type === "submit" || type === "button") && (!submitter || field !== submitter)) {
                return;
            }
            if (field.name) {
                signals.push(field.name);
            }
            if (field.value) {
                signals.push(field.value);
            }
            if (field === submitter && field.textContent) {
                signals.push(field.textContent);
            }
        });
    }

    function getSignalText(form, submitter) {
        var signals = [
            form.getAttribute("action") || "",
            form.getAttribute("data-confirm-message") || "",
            form.getAttribute("data-confirm-delete") || ""
        ];

        if (submitter) {
            signals.push(submitter.getAttribute("data-confirm-message") || "");
            signals.push(submitter.getAttribute("data-confirm-delete") || "");
            signals.push(submitter.getAttribute("aria-label") || "");
            signals.push(submitter.getAttribute("title") || "");
            signals.push(submitter.textContent || "");
        }

        appendFormSignals(signals, form, submitter);
        return signals.join(" ").replace(/\s+/g, " ").trim();
    }

    function isExplicitlyConfirmed(form, submitter) {
        return form.hasAttribute("data-confirm-delete") ||
            !!(submitter && submitter.hasAttribute("data-confirm-delete"));
    }

    function isDestructiveForm(form, submitter) {
        if ((form.method || "get").toLowerCase() === "get") {
            return false;
        }
        if (isExplicitlyConfirmed(form, submitter)) {
            return true;
        }
        return DESTRUCTIVE_PATTERN.test(getSignalText(form, submitter));
    }

    function getMessage(form, submitter) {
        var explicit = (submitter && submitter.getAttribute("data-confirm-message")) ||
            form.getAttribute("data-confirm-message");
        if (explicit) {
            return explicit;
        }

        var signalText = getSignalText(form, submitter).toLowerCase();
        if (signalText.indexOf("coupon") !== -1) {
            return "Remove this coupon?";
        }
        if (signalText.indexOf("wishlist") !== -1) {
            return "Remove this item from your wishlist?";
        }
        if (signalText.indexOf("cart") !== -1 || signalText.indexOf("item_index") !== -1) {
            return "Remove this item from your cart?";
        }
        if (signalText.indexOf("address") !== -1) {
            return "Delete this address?";
        }
        if (signalText.indexOf("review") !== -1) {
            return "Delete this review?";
        }
        if (signalText.indexOf("dismiss") !== -1 || signalText.indexOf("notification") !== -1) {
            return "Dismiss this notification?";
        }
        if (signalText.indexOf("disconnect") !== -1) {
            return "Disconnect this integration?";
        }

        return DEFAULT_MESSAGE;
    }

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!isElement(form) || form.tagName !== "FORM") {
            return;
        }
        var submitter = getSubmitter(event, form);
        if (isIgnoredForm(form) || hasInlineConfirm(form, submitter)) {
            return;
        }
        if (!isDestructiveForm(form, submitter)) {
            return;
        }
        if (!confirmDelete(getMessage(form, submitter))) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener("click", function (event) {
        var trigger = event.target && event.target.closest("[data-confirm-delete]");
        if (!trigger || isSubmitControl(trigger)) {
            return;
        }
        if ((trigger.getAttribute("onclick") || "").match(/confirm\s*\(|confirmDelete\s*\(/i)) {
            return;
        }
        if (!confirmDelete(trigger.getAttribute("data-confirm-message") || DEFAULT_MESSAGE)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    window.athinaConfirmDelete = confirmDelete;
})();
