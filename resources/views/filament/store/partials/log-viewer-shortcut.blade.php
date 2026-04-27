<script>
    (function () {
        const LOG_VIEWER_URL = @js(\App\Filament\Pages\LogViewer::getUrl(tenant: \Filament\Facades\Filament::getTenant()));

        document.addEventListener('keydown', function (e) {
            if (e.metaKey && e.key === 'l') {
                e.preventDefault();

                // If already on the log viewer, go back
                if (window.location.href.includes('/log-viewer')) {
                    window.history.back();
                    return;
                }

                window.location.href = LOG_VIEWER_URL;
            }
        });
    })();
</script>
