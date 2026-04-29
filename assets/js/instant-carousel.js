(function () {
    "use strict";

    var SLIDE_DUR = 420;
    var HOVER_INTERVAL = 2000;

    var MOVING_CLASSES = [
        "carousel-item-next",
        "carousel-item-prev",
        "carousel-item-start",
        "carousel-item-end"
    ];

    function getItems(carousel) {
        return Array.prototype.slice.call(carousel.querySelectorAll(".carousel-item"));
    }

    function cleanupItem(item) {
        MOVING_CLASSES.forEach(function (cls) { item.classList.remove(cls); });
    }

    function getActiveIndex(items) {
        var idx = items.findIndex(function (item) { return item.classList.contains("active"); });
        return idx >= 0 ? idx : 0;
    }

    function syncDots(carousel, index) {
        var wrap = carousel.querySelector(".shop-carousel-dots");
        if (!wrap) return;
        wrap.querySelectorAll(".shop-carousel-dot").forEach(function (dot, i) {
            dot.classList.toggle("is-active", i === index);
        });
    }

    function setActive(carousel, index) {
        var items = getItems(carousel);
        if (items.length === 0) return;
        var n = ((index % items.length) + items.length) % items.length;
        items.forEach(function (item, i) {
            cleanupItem(item);
            item.classList.remove("shop-entering", "shop-leaving");
            item.classList.toggle("active", i === n);
        });
        carousel.dataset.instantCarouselIndex = String(n);
        syncDots(carousel, n);
    }

    function smoothStep(carousel, direction) {
        if (carousel._sliding) return;
        var items = getItems(carousel);
        if (items.length < 2) return;

        var fromIdx = getActiveIndex(items);
        var toIdx = ((fromIdx + direction) % items.length + items.length) % items.length;
        var leaving = items[fromIdx];
        var entering = items[toIdx];

        carousel._sliding = true;
        carousel._pendingIndex = toIdx;

        entering.classList.add("active", "shop-entering");
        leaving.classList.add("shop-leaving");

        carousel._slideTimer = setTimeout(function () {
            leaving.classList.remove("active", "shop-leaving");
            entering.classList.remove("shop-entering");
            carousel.dataset.instantCarouselIndex = String(toIdx);
            syncDots(carousel, toIdx);
            carousel._sliding = false;
            carousel._pendingIndex = null;
        }, SLIDE_DUR);
    }

    function step(carousel, direction) {
        var items = getItems(carousel);
        if (items.length < 2) return;
        setActive(carousel, getActiveIndex(items) + direction);
    }

    function preloadCarouselImages(carousel) {
        carousel.querySelectorAll("img").forEach(function (img) {
            if (img.dataset.instantCarouselPreloaded === "1") return;
            img.dataset.instantCarouselPreloaded = "1";
            img.loading = "eager";
            if (!img.complete && img.src) {
                var pre = new Image();
                pre.src = img.currentSrc || img.src;
            }
        });
    }

    function startHoverCycle(carousel) {
        if (carousel._hoverTimer) return;
        if (getItems(carousel).length < 2) return;
        carousel._hoverTimer = setInterval(function () {
            smoothStep(carousel, 1);
        }, HOVER_INTERVAL);
    }

    function stopHoverCycle(carousel) {
        clearInterval(carousel._hoverTimer);
        carousel._hoverTimer = null;
        clearTimeout(carousel._slideTimer);
        carousel._slideTimer = null;
        var keepIndex = carousel._sliding && carousel._pendingIndex !== null
            ? carousel._pendingIndex
            : getActiveIndex(getItems(carousel));
        carousel._sliding = false;
        carousel._pendingIndex = null;
        setActive(carousel, keepIndex);
    }

    function initTouchNavigation(carousel) {
        if (carousel.dataset.instantCarouselTouchReady === "1") return;
        if (getItems(carousel).length < 2) return;

        carousel.dataset.instantCarouselTouchReady = "1";

        var startX = 0;
        var startY = 0;
        var lastX = 0;
        var lastY = 0;
        var isTracking = false;
        var isHorizontalSwipe = false;

        carousel.addEventListener("touchstart", function (e) {
            if (!e.touches || e.touches.length !== 1) return;
            preloadCarouselImages(carousel);
            isTracking = true;
            isHorizontalSwipe = false;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            lastX = startX;
            lastY = startY;
        }, { passive: true });

        carousel.addEventListener("touchmove", function (e) {
            if (!isTracking || !e.touches || e.touches.length !== 1) return;
            lastX = e.touches[0].clientX;
            lastY = e.touches[0].clientY;

            var dx = lastX - startX;
            var dy = lastY - startY;
            if (Math.abs(dx) > 12 && Math.abs(dx) > Math.abs(dy) * 1.25) {
                isHorizontalSwipe = true;
                if (e.cancelable) e.preventDefault();
            }
        }, { passive: false });

        carousel.addEventListener("touchend", function () {
            if (!isTracking) return;
            var dx = lastX - startX;
            isTracking = false;

            if (!isHorizontalSwipe || Math.abs(dx) < 38) return;
            step(carousel, dx < 0 ? 1 : -1);
            carousel._suppressNextClick = true;
            setTimeout(function () {
                carousel._suppressNextClick = false;
            }, 450);
        }, { passive: true });

        carousel.addEventListener("click", function (e) {
            if (!carousel._suppressNextClick) return;
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === "function") e.stopImmediatePropagation();
            carousel._suppressNextClick = false;
        }, true);
    }

    function initCarousel(carousel) {
        if (!carousel || carousel.dataset.instantCarouselReady === "1") return;

        carousel.dataset.instantCarouselReady = "1";
        carousel.setAttribute("data-bs-ride", "false");
        carousel.setAttribute("data-bs-interval", "false");
        carousel.setAttribute("data-bs-touch", "false");

        if (window.bootstrap && window.bootstrap.Carousel) {
            var inst = window.bootstrap.Carousel.getInstance(carousel);
            if (inst) inst.dispose();
        }

        setActive(carousel, getActiveIndex(getItems(carousel)));

        carousel.addEventListener("mouseenter", function () { preloadCarouselImages(carousel); }, { once: true });
        carousel.addEventListener("focusin",    function () { preloadCarouselImages(carousel); }, { once: true });
        carousel.addEventListener("touchstart", function () { preloadCarouselImages(carousel); }, { once: true, passive: true });
        initTouchNavigation(carousel);

        carousel.querySelectorAll(".carousel-control-prev, .carousel-control-next").forEach(function (ctrl) {
            ctrl.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === "function") e.stopImmediatePropagation();
                preloadCarouselImages(carousel);
                step(carousel, ctrl.getAttribute("data-bs-slide") === "prev" ? -1 : 1);
            }, true);
        });

        if (!window.matchMedia("(hover: hover) and (pointer: fine)").matches) return;
        if (getItems(carousel).length < 2) return;

        var wrap = carousel.closest(".shop-product-image") || carousel;
        wrap.addEventListener("mouseenter", function () {
            preloadCarouselImages(carousel);
            startHoverCycle(carousel);
        });
        wrap.addEventListener("mouseleave", function () {
            stopHoverCycle(carousel);
        });
    }

    function initAll() {
        document.querySelectorAll(".shop-carousel, #product-carousel").forEach(initCarousel);
    }

    window.athinaInstantCarouselInit  = initCarousel;
    window.athinaInstantCarouselTo    = setActive;
    window.athinaInstantCarouselStep  = step;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }
})();
