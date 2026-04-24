(function () {
    "use strict";

    var STYLE_ID = "athina-date-input-format-styles";
    var DATE_ERROR = "Use DD/MM/YYYY.";

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }

        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = [
            ".app-date-field{position:relative;display:block;width:100%;}",
            ".app-date-field>.app-date-proxy{width:100%;padding-right:48px!important;}",
            ".app-date-picker-button{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:0;border-radius:999px;background:#fff;color:#2f2a3b;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:none;}",
            ".app-date-picker-button:hover{background:#f4e9ff;color:#6a0dad;}",
            ".app-date-picker-button:focus-visible{outline:2px solid #9a58eb;outline-offset:2px;}",
            ".app-date-native{position:absolute!important;left:0!important;bottom:0!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;}"
        ].join("");
        document.head.appendChild(style);
    }

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function isoToDisplay(value) {
        var match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
            return "";
        }
        return match[3] + "/" + match[2] + "/" + match[1];
    }

    function displayToIso(value) {
        var trimmed = String(value || "").trim();
        if (trimmed === "") {
            return "";
        }

        var match = trimmed.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!match) {
            return null;
        }

        var day = Number(match[1]);
        var month = Number(match[2]);
        var year = Number(match[3]);
        var date = new Date(year, month - 1, day);

        if (
            date.getFullYear() !== year ||
            date.getMonth() !== month - 1 ||
            date.getDate() !== day
        ) {
            return null;
        }

        return String(year).padStart(4, "0") + "-" + pad(month) + "-" + pad(day);
    }

    function formatPartial(value) {
        var raw = String(value || "").replace(/\D/g, "").slice(0, 8);
        if (raw.length <= 2) {
            return raw;
        }
        if (raw.length <= 4) {
            return raw.slice(0, 2) + "/" + raw.slice(2);
        }
        return raw.slice(0, 2) + "/" + raw.slice(2, 4) + "/" + raw.slice(4);
    }

    function setProxyValidity(proxy, nativeInput, isoValue) {
        if (isoValue === null) {
            proxy.setCustomValidity(DATE_ERROR);
            nativeInput.value = "";
            return;
        }

        if (isoValue !== "") {
            var min = nativeInput.getAttribute("min") || "";
            var max = nativeInput.getAttribute("max") || "";
            if (min !== "" && isoValue < min) {
                proxy.setCustomValidity("Choose a date after " + isoToDisplay(min) + ".");
                nativeInput.value = "";
                return;
            }
            if (max !== "" && isoValue > max) {
                proxy.setCustomValidity("Choose a date before " + isoToDisplay(max) + ".");
                nativeInput.value = "";
                return;
            }
        }

        proxy.setCustomValidity("");
        nativeInput.value = isoValue;
    }

    function enhanceDateInput(nativeInput) {
        if (!nativeInput || nativeInput.dataset.appDateEnhanced === "1") {
            return;
        }

        nativeInput.dataset.appDateEnhanced = "1";
        nativeInput.setAttribute("lang", "en-GB");

        var originalId = nativeInput.id || "";
        var originalName = nativeInput.name || "";
        var wasRequired = nativeInput.required;
        var proxy = document.createElement("input");
        var button = document.createElement("button");
        var wrapper = document.createElement("span");

        proxy.type = "text";
        proxy.inputMode = "numeric";
        proxy.autocomplete = "off";
        proxy.placeholder = "DD/MM/YYYY";
        proxy.className = nativeInput.className;
        proxy.classList.add("app-date-proxy");
        proxy.value = isoToDisplay(nativeInput.value);
        proxy.dataset.dateName = originalName;
        proxy.setAttribute("aria-label", nativeInput.getAttribute("aria-label") || "Date in DD/MM/YYYY format");
        if (originalId !== "") {
            proxy.id = originalId;
            nativeInput.id = originalId + "_native";
        }
        if (wasRequired) {
            proxy.required = true;
        }
        if (nativeInput.disabled) {
            proxy.disabled = true;
        }
        if (nativeInput.readOnly) {
            proxy.readOnly = true;
        }

        button.type = "button";
        button.className = "app-date-picker-button";
        button.setAttribute("aria-label", "Open calendar");
        button.innerHTML = '<i class="fas fa-calendar-alt" aria-hidden="true"></i>';
        if (nativeInput.disabled || nativeInput.readOnly) {
            button.disabled = true;
        }

        wrapper.className = "app-date-field";
        nativeInput.parentNode.insertBefore(wrapper, nativeInput);
        wrapper.appendChild(proxy);
        wrapper.appendChild(button);
        wrapper.appendChild(nativeInput);

        nativeInput.required = false;
        nativeInput.tabIndex = -1;
        nativeInput.classList.add("app-date-native");
        nativeInput.setAttribute("aria-hidden", "true");

        function syncFromProxy() {
            var isoValue = displayToIso(proxy.value);
            setProxyValidity(proxy, nativeInput, isoValue);
        }

        proxy.addEventListener("input", function () {
            proxy.value = formatPartial(proxy.value);
            syncFromProxy();
        });

        proxy.addEventListener("blur", syncFromProxy);

        nativeInput.addEventListener("change", function () {
            proxy.value = isoToDisplay(nativeInput.value);
            proxy.setCustomValidity("");
        });

        button.addEventListener("click", function () {
            if (nativeInput.disabled || nativeInput.readOnly) {
                return;
            }
            try {
                if (typeof nativeInput.showPicker === "function") {
                    nativeInput.showPicker();
                } else {
                    nativeInput.click();
                    nativeInput.focus({ preventScroll: true });
                }
            } catch (error) {
                nativeInput.click();
            }
        });

        if (nativeInput.form) {
            nativeInput.form.addEventListener("submit", syncFromProxy);
        }

        syncFromProxy();
    }

    function init() {
        injectStyles();
        document.querySelectorAll('input[type="date"]:not([data-keep-native-date])').forEach(enhanceDateInput);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
