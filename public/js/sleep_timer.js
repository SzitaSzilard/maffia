/**
 * Sleep Timer - Countdown display for active sleep
 * Uses absolute end time for accurate countdown across HTMX navigations
 */
let sleepInterval = null;
let sleepEndTime = null;

function updateSleepTimer() {
    const timerEl = document.getElementById('sleep-timer');

    // Clear any existing interval first
    if (sleepInterval) {
        clearInterval(sleepInterval);
        sleepInterval = null;
    }

    // Reset sleepEndTime each time to avoid stale values
    sleepEndTime = null;

    // Exit silently if no timer element - this is normal on non-sleep pages
    if (!timerEl) {
        return;
    }

    console.log('[Sleep Timer] Timer element found, initializing...');

    // Get end time from element (absolute timestamp)
    const endTimeStr = timerEl.dataset.endTime;

    if (!endTimeStr) {
        console.log('[Sleep Timer] No end time attribute');
        return;
    }

    // Parse the datetime
    try {
        // Replace space with T for ISO format compatibility if needed
        const isoStr = endTimeStr.replace(' ', 'T');
        sleepEndTime = new Date(isoStr).getTime();
        console.log('[Sleep Timer] End time:', new Date(sleepEndTime).toLocaleTimeString());
    } catch (e) {
        console.error('[Sleep Timer] Date parse error:', e);
        return;
    }

    if (!sleepEndTime || isNaN(sleepEndTime)) {
        console.log('[Sleep Timer] Invalid end time');
        return;
    }

    function tick() {
        const now = Date.now();
        const remainingMs = sleepEndTime - now;
        const remainingSec = Math.max(0, Math.floor(remainingMs / 1000));

        const displayEl = document.getElementById('sleep-timer');
        if (!displayEl) {
            // Element was removed, stop timer
            clearInterval(sleepInterval);
            sleepInterval = null;
            return;
        }

        if (remainingSec <= 0) {
            console.log('[Sleep Timer] Finished');
            displayEl.textContent = "0 perc 0 másodperc (Felkelhetsz)";
            clearInterval(sleepInterval);
            sleepInterval = null;
            return;
        }

        const hours = Math.floor(remainingSec / 3600);
        const minutes = Math.floor((remainingSec % 3600) / 60);
        const seconds = remainingSec % 60;

        let text = "";
        if (hours > 0) {
            text += `${hours} óra `;
        }
        text += `${minutes} perc ${seconds} másodperc`;

        displayEl.textContent = text;
    }

    tick();
    sleepInterval = setInterval(tick, 1000);
}

// Setup everything after DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    // Initial timer check
    updateSleepTimer();

    // HTMX event listener
    document.body.addEventListener('htmx:afterSettle', function (evt) {
        // Only check for timer on main content swaps or relevant updates
        updateSleepTimer();
    });
});
