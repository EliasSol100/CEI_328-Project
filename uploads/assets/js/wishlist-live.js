// Live wishlist updates: no page reload, instant heart/counter/message updates.
(function () {
    const endpoint = "wishlist_toggle.php";

    function isHeartForm(form) {
        if (!form || form.tagName !== "FORM") return false;
        const button = form.querySelector("button.wishlist-btn, button.shop-fav");
        const actionInput = form.querySelector('input[name="action"]');
        return !!(button && actionInput);
    }

    function getWishlistIdentifier(form) {
        const keyInput = form.querySelector('input[name="product_key"]');
        const idInput = form.querySelector('input[name="product_id"]');
        if (keyInput && keyInput.value) {
            return { type: "product_key", value: keyInput.value };
        }
        if (idInput && idInput.value) {
            return { type: "product_id", value: idInput.value };
        }
        return null;
    }

    function setCounter(count) {
        const icon = document.querySelector(".wishlist-icon");
        if (!icon) return;

        let badge = icon.querySelector(".wishlist-count");
        if (count > 0) {
            if (!badge) {
                badge = document.createElement("span");
                badge.className = "wishlist-count";
                icon.appendChild(badge);
            }
            badge.textContent = String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    function setFormState(form, inWishlist) {
        const actionInput = form.querySelector('input[name="action"]');
        const button = form.querySelector("button.wishlist-btn, button.shop-fav");
        const icon = button ? button.querySelector("i.fa-heart") : null;

        if (!actionInput || !button) return;

        // Keep the expected action per form type.
        if (form.querySelector('input[name="product_key"]')) {
            actionInput.value = inWishlist ? "remove_wishlist_item" : "add_wishlist_item";
        } else {
            actionInput.value = "toggle_wishlist_item";
        }

        button.classList.toggle("is-active", inWishlist);
        button.title = inWishlist ? "Remove from wishlist" : "Add to wishlist";
        button.setAttribute("aria-label", button.title);

        if (icon) {
            icon.classList.toggle("fas", inWishlist);
            icon.classList.toggle("far", !inWishlist);
        }
    }

    function syncMatchingForms(identifier, inWishlist) {
        if (!identifier) return;
        document.querySelectorAll("form").forEach((form) => {
            if (!isHeartForm(form)) return;
            const current = getWishlistIdentifier(form);
            if (!current) return;
            if (current.type === identifier.type && String(current.value) === String(identifier.value)) {
                setFormState(form, inWishlist);
            }
        });
    }

    function getFeedbackEl() {
        let el = document.querySelector(".wishlist-feedback");
        if (!el) {
            el = document.createElement("div");
            el.className = "wishlist-feedback";
            document.body.appendChild(el);
        }
        return el;
    }

    function showFeedback(message, type) {
        const el = getFeedbackEl();
        el.textContent = message || "";
        el.classList.remove("is-success", "is-error", "is-visible");
        el.classList.add(type === "success" ? "is-success" : "is-error");
        el.classList.add("is-visible");

        window.clearTimeout(el._hideTimer);
        el._hideTimer = window.setTimeout(() => {
            el.classList.remove("is-visible");
        }, 2200);
    }

    async function submitHeartForm(form) {
        const button = form.querySelector("button.wishlist-btn, button.shop-fav");
        const identifier = getWishlistIdentifier(form);
        if (!identifier) return;

        if (button) button.disabled = true;

        try {
            const response = await fetch(endpoint, {
                method: "POST",
                body: new FormData(form),
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "application/json"
                },
                credentials: "same-origin"
            });

            if (!response.ok) {
                throw new Error("Wishlist request failed (" + response.status + ").");
            }

            const raw = await response.text();
            const cleaned = raw.replace(/^\uFEFF+/, "").trim();
            const data = cleaned ? JSON.parse(cleaned) : null;
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update wishlist.");
            }

            const inWishlist = !!data.inWishlist;
            const updatedIdentifier =
                data.productKey ? { type: "product_key", value: data.productKey } :
                (data.productId ? { type: "product_id", value: data.productId } : identifier);

            syncMatchingForms(updatedIdentifier, inWishlist);
            if (typeof data.wishlistCount !== "undefined") {
                setCounter(Number(data.wishlistCount) || 0);
            }
            showFeedback(data.message || "Wishlist updated.", "success");
        } catch (err) {
            showFeedback(err.message || "Could not update wishlist.", "error");
        } finally {
            if (button) button.disabled = false;
        }
    }

    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (!isHeartForm(form)) return;
        event.preventDefault();
        submitHeartForm(form);
    });

    // Initialize active class from current icon class.
    document.querySelectorAll("form").forEach((form) => {
        if (!isHeartForm(form)) return;
        const icon = form.querySelector("i.fa-heart");
        const inWishlist = !!(icon && icon.classList.contains("fas"));
        setFormState(form, inWishlist);
    });
})();
