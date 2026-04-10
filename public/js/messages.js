/**
 * MessagesModule - Üzenetek kijelölés és törlés kezelése
 */
if (typeof MessagesModule === 'undefined') {
    var MessagesModule = {

        /**
         * Összes checkbox kijelölése/kijelölés törlése
         */
        selectAll: function () {
            const checkboxes = document.querySelectorAll('#messages-form input[type="checkbox"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);

            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
        },

        /**
         * Kijelölt üzenetek számának lekérdezése
         */
        getSelectedCount: function () {
            return document.querySelectorAll('#messages-form input[type="checkbox"]:checked').length;
        },

        /**
         * Delete modal megjelenítése
         */
        showDeleteModal: function () {
            const count = this.getSelectedCount();
            if (count === 0) {
                this.showToast('Jelölj ki legalább egy üzenetet!', 'warning');
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

            // Kijelölt üzenetek elhalványítása
            const checkedBoxes = document.querySelectorAll('#messages-form input[type="checkbox"]:checked');

            checkedBoxes.forEach(checkbox => {
                const messageItem = checkbox.closest('.message-item');
                if (messageItem) {
                    messageItem.style.opacity = '0';
                    messageItem.style.transform = 'translateX(-20px)';
                }
            });

            // Kis késleltetés után submit
            setTimeout(() => {
                document.getElementById('messages-form').submit();
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
         * Olvasatlan badge elhalványítása 3 másodperc alatt
         */
        fadeOutUnreadBadge: function () {
            // Csak üzenetek oldalon fusson (ellenőrizzük a tabs létezését)
            const messagesPage = document.querySelector('.tabs');
            if (!messagesPage) return;

            // Sidebar Üzenetek badge - mindig elhalványul ha üzenetek oldalon vagyunk
            const sidebarBadge = document.querySelector('.unread-badge');
            if (sidebarBadge) {
                this.fadeElement(sidebarBadge);
            }
        },

        /**
         * Sidebar badge elhalványítása (kattintáskor hívódik)
         */
        fadeSidebarBadge: function () {
            const badge = document.querySelector('.unread-badge');
            if (badge) {
                this.fadeElement(badge);
            }
        },

        /**
         * Elem elhalványítása
         */
        fadeElement: function (element) {
            element.style.transition = 'opacity 3s ease-out';
            setTimeout(() => {
                element.style.opacity = '0';
            }, 50);
            setTimeout(() => {
                element.remove();
            }, 3100);
        },

        /**
         * Inicializálás
         */
        init: function () {
            // Badge fade az onclick-ben van kezelve a sidebar-on
        }
    };

    // Auto-init on page load and HTMX swaps
    document.addEventListener('DOMContentLoaded', () => MessagesModule.init());
    document.addEventListener('htmx:afterSwap', () => MessagesModule.init());

} // end if MessagesModule undefined
