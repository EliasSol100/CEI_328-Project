(function () {
    const body = document.body;
    if (!body || !body.classList.contains("site-page")) {
        return;
    }

    const LONG_PAGE_RATIO = 1.9;
    const LONG_PAGE_MIN_EXTRA = 680;
    const CAROUSEL_ROOT_MARGIN = 180;
    let resizeFrame = 0;
    let carouselObserver = null;

    function isLongScrollPage() {
        const root = document.documentElement;
        const viewportHeight = Math.max(window.innerHeight || 0, 1);
        const pageHeight = Math.max(root.scrollHeight || 0, body.scrollHeight || 0);
        const extraScroll = Math.max(0, pageHeight - viewportHeight);

        return extraScroll > Math.max(LONG_PAGE_MIN_EXTRA, viewportHeight * 0.9)
            && pageHeight > viewportHeight * LONG_PAGE_RATIO;
    }

    function updateLongPageState() {
        body.classList.toggle("is-long-scroll-page", isLongScrollPage());
    }

    function queueLongPageUpdate() {
        if (resizeFrame) {
            window.cancelAnimationFrame(resizeFrame);
        }
        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = 0;
            updateLongPageState();
        });
    }

    function getBootstrapCarousel(element) {
        if (!window.bootstrap || !window.bootstrap.Carousel) {
            return null;
        }

        const intervalAttr = element.getAttribute("data-bs-interval");
        const interval = intervalAttr === "false"
            ? false
            : (Number(intervalAttr) || 5000);

        return window.bootstrap.Carousel.getOrCreateInstance(element, {
            interval: interval,
            pause: false,
            ride: false,
            touch: true,
            wrap: true
        });
    }

    function setCarouselRunning(element, shouldRun) {
        const instance = getBootstrapCarousel(element);
        if (!instance) {
            return;
        }

        if (element.getAttribute("data-bs-interval") === "false") {
            instance.pause();
            return;
        }

        if (shouldRun && !document.hidden) {
            instance.cycle();
        } else {
            instance.pause();
        }
    }

    function syncCarouselVisibility() {
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        document.querySelectorAll('.carousel[data-bs-ride="carousel"]').forEach(function (element) {
            const rect = element.getBoundingClientRect();
            const isNearViewport = rect.bottom >= -CAROUSEL_ROOT_MARGIN
                && rect.top <= viewportHeight + CAROUSEL_ROOT_MARGIN;

            setCarouselRunning(element, isNearViewport);
        });
    }

    function observeCarousels() {
        if (!("IntersectionObserver" in window)) {
            syncCarouselVisibility();
            return;
        }

        const carousels = document.querySelectorAll('.carousel[data-bs-ride="carousel"]');
        if (!carousels.length) {
            return;
        }

        carouselObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                setCarouselRunning(entry.target, entry.isIntersecting && entry.intersectionRatio > 0.18);
            });
        }, {
            root: null,
            rootMargin: CAROUSEL_ROOT_MARGIN + "px 0px",
            threshold: [0, 0.18]
        });

        carousels.forEach(function (element) {
            carouselObserver.observe(element);
        });
    }

    function init() {
        updateLongPageState();
        observeCarousels();
        syncCarouselVisibility();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init, { once: true });
    } else {
        init();
    }

    window.addEventListener("load", queueLongPageUpdate, { once: true });
    window.addEventListener("resize", function () {
        queueLongPageUpdate();
        syncCarouselVisibility();
    });
    document.addEventListener("visibilitychange", syncCarouselVisibility);
})();
