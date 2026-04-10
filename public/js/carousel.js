/**
 * Restaurant Menu Carousel
 * 
 * [2025-12-29 14:57:54] Refactoring: Inline JS kiszervezése external fájlba
 * Korábban a restaurant/index.twig-ben volt inline. Most újrafelhasználható.
 */

/**
 * Restaurant Menu Carousel
 * Refactored to work reliably with HTMX and standard loads.
 */

(function () {
    let currentIndex = 0;
    let items = [];
    let totalItems = 0;

    /**
     * Carousel inicializálása
     */
    function initCarousel() {
        items = document.querySelectorAll('.menu-item');
        totalItems = items.length;

        // Ensure at least one item is active if none are
        if (totalItems > 0 && !document.querySelector('.menu-item.active')) {
            currentIndex = 0;
            showItem(0);
        } else {
            // Find currently active index if exists
            items.forEach((item, index) => {
                if (item.classList.contains('active')) currentIndex = index;
            });
        }
    }

    /**
     * Adott index megjelenítése
     */
    function showItem(index) {
        if (totalItems === 0) return;

        items.forEach(item => item.classList.remove('active'));

        // Wrap around
        if (index >= totalItems) {
            currentIndex = 0;
        } else if (index < 0) {
            currentIndex = totalItems - 1;
        } else {
            currentIndex = index;
        }

        const currentItem = document.getElementById('item-' + currentIndex);
        if (currentItem) {
            currentItem.classList.add('active');
        }
    }

    // Expose functions to global scope for onclick handlers
    window.nextItem = function () {
        showItem(currentIndex + 1);
    };

    window.prevItem = function () {
        showItem(currentIndex - 1);
    };

    // Auto-init immediately when script runs (essential for HTMX)
    initCarousel();

    // Also listen for potential re-draws if needed, but immediate init usually covers HTMX injection
    // We remove the global listenter to avoid duplicate bindings on navigation
})();
