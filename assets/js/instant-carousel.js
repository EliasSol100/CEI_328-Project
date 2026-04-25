(function () {
    "use strict";

    var SLIDE_DUR = 420; // ms — must match CSS animation duration
    var HOVER_INTERVAL = 2000; // ms between auto-advances on hover

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

    // Instant jump — no animation (used for reset on mouse-leave)
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

    // Smooth slide to next/prev — used by hover auto-advance
    function smoothStep(carousel, direction) {
        if (carousel._sliding) return;
        var items = getItems(carousel);
        if (items.length < 2) return;

        var fromIdx = getActiveIndex(items);
        var toIdx = ((fromIdx + direction) % items.length + items.length) % items.length;
        var leaving = items[fromIdx];
        var entering = items[toIdx];

        carousel._sliding = true;

        // entering starts off-screen; add active so it's visible, then animate in
        entering.classList.add("active", "shop-entering");
        leaving.classList.add("shop-leaving");

        carousel._slideTimer = setTimeout(function () {
            leaving.classList.remove("active", "shop-leaving");
            entering.classList.remove("shop-entering");
            carousel.dataset.instantCarouselIndex = String(toIdx);
            syncDots(carousel, toIdx);
            carousel._sliding = false;
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
        // First advance fires after the interval (not immediately)
        carousel._hoverTimer = setInterval(function () {
            smoothStep(carousel, 1);
        }, HOVER_INTERVAL);
    }

    function stopHoverCycle(carousel) {
        clearInterval(carousel._hoverTimer);
        carousel._hoverTimer = null;
        clearTimeout(carousel._slideTimer);
        carousel._slideTimer = null;
        // Clean up any in-progress animation classes before resetting
        getItems(carousel).forEach(function (item) {
            item.classList.remove("shop-entering", "shop-leaving");
        });
        carousel._sliding = false;
        setActive(carousel, 0);
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

        // Prev/next button clicks (instant, not animated)
        carousel.querySelectorAll(".carousel-control-prev, .carousel-control-next").forEach(function (ctrl) {
            ctrl.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === "function") e.stopImmediatePropagation();
                preloadCarouselImages(carousel);
                step(carousel, ctrl.getAttribute("data-bs-slide") === "prev" ? -1 : 1);
            }, true);
        });

        // Hover auto-advance: mouse devices only
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
