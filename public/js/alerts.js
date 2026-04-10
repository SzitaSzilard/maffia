// Helper function to fade out an alert
function fadeOutAlert(alert) {
    if (!alert) return;
    alert.style.transition = 'opacity 0.2s ease-out';
    alert.style.opacity = '0';
    setTimeout(() => { alert.style.display = 'none'; alert.remove(); }, 200);
}

// Auto-fadeout setup
function setupAutoFade(alert) {
    if (alert.dataset.autofade) return;
    alert.dataset.autofade = "true";
    setTimeout(() => {
        fadeOutAlert(alert);
    }, 5000); // 5 seconds
}

// Start an observer to watch for any new alerts being injected via HTMX or anything else
const flashObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) { // ELEMENT_NODE
                // Determine if this node itself is an alert or if it contains one
                const alertClasses = ['.alert-success', '.alert-error', '.alert-warning', '.alert-danger', '.error-box', '.msg-box'];
                const selector = alertClasses.join(', ');
                
                let matches = [];
                if (node.matches && node.matches(selector)) {
                    matches.push(node);
                }
                if (node.querySelectorAll) {
                    node.querySelectorAll(selector).forEach(el => matches.push(el));
                }
                
                // For any matched alert, if it's floating, set up autofade
                matches.forEach(alert => {
                    const pos = window.getComputedStyle(alert).position;
                    if (pos === 'fixed' || pos === 'absolute') {
                        setupAutoFade(alert);
                    }
                });
            }
        });
    });
});

// Setup Observer to watch the entire body for updates
document.addEventListener('DOMContentLoaded', () => {
    // 1. Setup auto-fade for existing static alerts
    document.querySelectorAll('.alert-success, .alert-error, .alert-warning, .alert-danger, .error-box, .msg-box').forEach(alert => {
        if (window.getComputedStyle(alert).position === 'fixed' || window.getComputedStyle(alert).position === 'absolute') {
            setupAutoFade(alert);
        }
    });
    
    // 2. Start monitoring for newly injected alerts
    flashObserver.observe(document.body, { childList: true, subtree: true });
});

// Maffia Alert handler for floating notifications (click to dismiss early)
document.addEventListener('click', function(e) {
    let alert = e.target.closest('.alert-success, .alert-error, .alert-warning, .alert-danger, .error-box, .msg-box');
    if (alert) {
        fadeOutAlert(alert);
    }
});

// Handle HTMX flashes from the server
document.addEventListener('showFlash', function(evt) {
    const flashData = evt.detail;
    if (!flashData || !flashData.message) return;
    
    const typeClass = flashData.type === 'error' ? 'alert-error' : 'alert-success';
    
    // Create new flash element
    const flashEl = document.createElement('div');
    flashEl.className = typeClass;
    flashEl.textContent = flashData.message;
    
    // Append to container or body
    const container = document.getElementById('flash-container');
    if (container) {
        container.appendChild(flashEl);
    } else {
        document.body.appendChild(flashEl);
    }

    // Give it a tiny delay to ensure transition works, then setup auto fade
    setTimeout(() => {
        setupAutoFade(flashEl);
    }, 10);
});
