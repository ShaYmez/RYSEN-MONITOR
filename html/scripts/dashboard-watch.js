(function (global) {
    var waitingHtml = '<div class="text-muted text-center p-3"><i class="fas fa-sync-alt fa-spin"></i> Waiting for monitor connection…</div>';

    function watchDashboardContent(elementId, intervalMs) {
        var el = document.getElementById(elementId);
        if (!el) {
            return;
        }
        var showingWait = false;
        setInterval(function () {
            if (el.innerHTML.trim() !== '') {
                showingWait = false;
                return;
            }
            if (!showingWait) {
                el.innerHTML = waitingHtml;
                showingWait = true;
            }
        }, intervalMs || 5000);
    }

    global.watchDashboardContent = watchDashboardContent;
})(window);
