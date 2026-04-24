(function () {
    "use strict";

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
        MOVING_CLASSES.forEach(function (className) {
            item.classList.remove(className);
        });
    }

    function getActiveIndex(items) {
        var index = items.findIndex(function (item) {
            return item.classList.contains("active");
        });
        return index >= 0 ? index : 0;
    }

    function setActive(carousel, index) {
        var items = getItems(carousel);
        if (items.length === 0) {
            return;
        }

        var normalizedIndex = ((index % items.length) + items.length) % items.length;
        items.forEach(function (item, itemIndex) {
            cleanupItem(item);
            item.classList.toggle("active", itemIndex === normalizedIndex);
        });
        carousel.dataset.instantCarouselIndex = String(normalizedIndex);
    }

    function step(carousel, direction) {
        var items = getItems(carousel);
        if (items.length < 2) {
            return;
        }
        setActive(carousel, getActiveIndex(items) + direction);
    }

    function directionFromControl(control) {
        return control.getAttribute("data-bs-slide") === "prev" ? -1 : 1;
    }

    function preloadCarouselImages(carousel) {
        carousel.querySelectorAll("img").forEach(function (img) {
            if (img.dataset.instantCarouselPreloaded === "1") {
                return;
            }
            img.dataset.instantCarouselPreloaded = "1";
            img.loading = "eager";
            if (!img.complete && img.src) {
                var preload = new Image();
                preload.src = img.currentSrc || img.src;
            }
        });
    }

    function initCarousel(carousel) {
        if (!carousel || carousel.dataset.instantCarouselReady === "1") {
            return;
        }

        carousel.dataset.instantCarouselReady = "1";
        carousel.setAttribute("data-bs-ride", "false");
        carousel.setAttribute("data-bs-interval", "false");
        carousel.setAttribute("data-bs-touch", "false");

        if (window.bootstrap && window.bootstrap.Carousel) {
            var instance = window.bootstrap.Carousel.getInstance(carousel);
            if (instance) {
                instance.dispose();
            }
        }

        setActive(carousel, getActiveIndex(getItems(carousel)));

        carousel.addEventListener("mouseenter", function () {
            preloadCarouselImages(carousel);
        }, { once: true });
        carousel.addEventListener("focusin", function () {
            preloadCarouselImages(carousel);
        }, { once: true });
        carousel.addEventListener("touchstart", function () {
            preloadCarouselImages(carousel);
        }, { once: true, passive: true });

        carousel.querySelectorAll(".carousel-control-prev, .carousel-control-next").forEach(function (control) {
            control.addEventListener("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === "function") {
                    event.stopImmediatePropagation();
                }
                preloadCarouselImages(carousel);
                step(carousel, directionFromControl(control));
            }, true);
        });
    }

    function initAll() {
        document.querySelectorAll(".shop-carousel, #product-carousel").forEach(initCarousel);
    }

    window.athinaInstantCarouselInit = initCarousel;
    window.athinaInstantCarouselTo = setActive;
    window.athinaInstantCarouselStep = step;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }
})();
