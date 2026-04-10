/**
 * NotificationsModule - Értesítések kijelölés és törlés kezelése
 */
if (typeof NotificationsModule === 'undefined') {
    var NotificationsModule = {

        /**
         * Összes checkbox kijelölése/kijelölés törlése
         */
        selectAll: function () {
            const checkboxes = document.querySelectorAll('#notifications-form input[type="checkbox"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);

            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
        },

        /**
         * Kijelölt értesítések számának lekérdezése
         */
        getSelectedCount: function () {
            return document.querySelectorAll('#notifications-form input[type="checkbox"]:checked').length;
        },

        /**
         * Delete modal megjelenítése
         */
        showDeleteModal: function () {
            const count = this.getSelectedCount();
            if (count === 0) {
                this.showToast('Jelölj ki legalább egy értesítést!', 'warning');
                return;
            }

            document.getElementById('delete-count').textContent = count;
            document.getElementById('delete-modal').style.display = 'block';
        },

        /**
         * Delete modal elrejtése
         */
        hideDeleteModal: function () {
            document.getElementById('delete-modal').style.display = 'none';
        },

        /**
         * Törlés megerősítése és animáció
         */
        confirmDelete: function () {
            this.hideDeleteModal();

            // Kijelölt értesítések elhalványítása
            const checkedBoxes = document.querySelectorAll('#notifications-form input[type="checkbox"]:checked');

            checkedBoxes.forEach(checkbox => {
                const notificationItem = checkbox.closest('.notification-item');
                if (notificationItem) {
                    notificationItem.style.opacity = '0';
                    notificationItem.style.transform = 'translateX(-20px)';
                }
            });

            // Kis késleltetés után submit
            setTimeout(() => {
                document.getElementById('notifications-form').submit();
            }, 500);
        },

        /**
         * Toast üzenet megjelenítése
         */
        showToast: function (message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 15px 25px; background: ' +
                (type === 'warning' ? '#f39c12' : '#27ae60') +
                '; color: #fff; border-radius: 4px; z-index: 1001; font-size: 14px;';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.transition = 'opacity 0.5s';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 500);
            }, 2000);
        },

        /**
         * Sidebar badge elhalványítása
         */
        fadeSidebarBadge: function () {
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.style.transition = 'opacity 3s ease-out';
                setTimeout(() => {
                    badge.style.opacity = '0';
                }, 500);
                setTimeout(() => {
                    badge.remove();
                }, 3500);
            }
        },

        /**
         * Inicializálás
         */
        init: function () {
            // Fade out notification badge ha értesítések oldalon vagyunk
            const notificationsPage = document.querySelector('.notifications-container');
            if (notificationsPage) {
                this.fadeSidebarBadge();
            }
        }
    };

    // Auto-init on page load and HTMX swaps
    document.addEventListener('DOMContentLoaded', () => NotificationsModule.init());
    document.addEventListener('htmx:afterSwap', () => NotificationsModule.init());

} // end if NotificationsModule undefined
