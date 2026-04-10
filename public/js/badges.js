/**
 * Badge Management - Generikus badge frissítés és animáció
 * 
 * [2025-12-29 14:52:40] Refactoring: Duplikált inline kód kiszervezése
 * Korábban a base_game.twig-ben 46 sor duplikált kód volt az üzenet
 * és értesítés badge-ek kezelésére. Most egy generikus függvényt használunk.
 */

/**
 * Generikus badge frissítő függvény
 * 
 * @param {string} eventName - Custom event neve (pl. 'updateUnreadBadge')
 * @param {string} linkSelector - Link CSS selector (pl. 'a[hx-get="/uzenetek"]')
 * @param {string} badgeClass - Badge CSS class (pl. 'unread-badge')
 */
function updateBadge(eventName, linkSelector, badgeClass) {
    document.body.addEventListener(eventName, function (evt) {
        var count = evt.detail.value !== undefined ? evt.detail.value : evt.detail;
        var menuLink = document.querySelector(linkSelector);
        if (!menuLink) return;

        var badge = menuLink.querySelector('.' + badgeClass);

        if (count > 0) {
            if (badge) {
                // Meglévő badge frissítése
                badge.classList.remove('badge-fading');
                badge.style.opacity = '1';
                badge.textContent = ' (' + count + ')';
            } else {
                // Új badge létrehozása
                var newBadge = document.createElement('span');
                newBadge.className = badgeClass;
                newBadge.style.cssText = 'color:#ff4444; font-weight:bold;';
                newBadge.textContent = ' (' + count + ')';
                menuLink.appendChild(newBadge);
            }
        } else if (badge && !badge.classList.contains('badge-fading')) {
            // Badge eltávolítása ha 0 a számláló
            badge.remove();
        }
    });
}

/**
 * Badge fade out és eltávolítás
 * 
 * @param {string} selector - Badge CSS selector (pl. '.unread-badge')
 */
function fadeBadge(selector) {
    var badge = document.querySelector(selector);
    if (badge) {
        badge.classList.add('badge-fading');
        setTimeout(function () {
            if (badge.parentNode) {
                badge.remove();
            }
        }, 3000);
    }
}

/**
 * Menu item highlight fade out
 * 
 * @param {HTMLElement} element - A menu link element (pl. this)
 */
function fadeMenuBackground(element) {
    if (!element) return;

    if (element.classList.contains('postal-menu-highlight')) {
        element.classList.remove('postal-menu-highlight');
        element.classList.add('postal-menu-fade');

        // Remove the fade class after the transition is complete so it returns to clean state
        setTimeout(function () {
            element.classList.remove('postal-menu-fade');
        }, 3000);

    } else if (element.classList.contains('oc-menu-highlight')) {
        element.classList.remove('oc-menu-highlight');
        element.classList.add('oc-menu-fade');

        setTimeout(function () {
            element.classList.remove('oc-menu-fade');
        }, 3000);
    }
}

// [2025-12-29 14:52:40] Badge event regisztrációk
// DOMContentLoaded után inicializáljuk a badge listener-eket
document.addEventListener('DOMContentLoaded', function () {
    // Üzenetek badge
    updateBadge('updateUnreadBadge', 'a[hx-get="/uzenetek"]', 'unread-badge');

    // Értesítések badge
    updateBadge('updateNotificationBadge', 'a[hx-get="/ertesitesek"]', 'notification-badge');

    // Combat badge
    updateBadge('updateCombatBadge', 'a[hx-get="/kuzdelmek"]', 'combat-badge');

    // Postal badge (custom handling for highlight background)
    document.body.addEventListener('updatePostalBadge', function (evt) {
        var count = evt.detail.value !== undefined ? evt.detail.value : evt.detail;
        var menuLink = document.querySelector('a[hx-get="/posta"]');
        if (!menuLink) return;

        if (count > 0) {
            menuLink.classList.add('postal-menu-highlight');
            menuLink.classList.remove('postal-menu-fade');
        } else {
            // Fade out the highlight
            fadeMenuBackground(menuLink);
        }
    });

    // Organized Crime badge (custom handling for red text and (!) indicator)
    document.body.addEventListener('updateOrganizedCrimeBadge', function (evt) {
        var count = evt.detail.value !== undefined ? evt.detail.value : evt.detail;
        var menuLink = document.querySelector('a[hx-get="/szervezett-bunozes"]');
        if (!menuLink) return;

        if (count > 0) {
            menuLink.classList.add('oc-menu-highlight');
            menuLink.classList.remove('oc-menu-fade');
        } else {
            if (menuLink.classList.contains('oc-menu-highlight')) {
                menuLink.classList.remove('oc-menu-highlight');
                menuLink.classList.add('oc-menu-fade');
                setTimeout(function () {
                    menuLink.classList.remove('oc-menu-fade');
                }, 3000);
            }
        }
    });

});
