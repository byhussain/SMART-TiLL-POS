<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparing Store Data</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<main class="relative isolate min-h-screen overflow-hidden">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute left-[-10%] top-[-18%] h-[34rem] w-[34rem] rounded-full bg-cyan-500/20 blur-3xl dark:bg-cyan-500/20"></div>
        <div class="absolute bottom-[-22%] right-[-8%] h-[38rem] w-[38rem] rounded-full bg-blue-500/20 blur-3xl dark:bg-blue-500/20"></div>
    </div>

    <div class="mx-auto flex min-h-screen w-full max-w-5xl items-center px-6 py-10 lg:px-10">
        <section class="grid w-full overflow-hidden rounded-3xl border border-slate-300 bg-white/90 shadow-2xl shadow-slate-300/50 backdrop-blur dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/40 lg:grid-cols-[1.15fr_0.85fr]">
            <div class="relative overflow-hidden border-b border-slate-200 p-8 sm:p-10 dark:border-white/10 lg:border-b-0 lg:border-r">
                <div class="absolute inset-0 bg-gradient-to-br from-cyan-400/15 via-transparent to-blue-500/15 dark:from-cyan-400/10 dark:to-blue-500/10"></div>
                <div class="relative">
                    <p class="inline-flex items-center rounded-full border border-cyan-300/50 bg-cyan-100 px-3 py-1 text-xs font-medium uppercase tracking-[0.14em] text-cyan-700 dark:border-cyan-300/30 dark:bg-cyan-400/10 dark:text-cyan-100">
                        Initial Cloud Sync
                    </p>
                    <h1 class="mt-5 text-3xl font-semibold leading-tight text-slate-900 dark:text-white sm:text-4xl">
                        Preparing {{ $store->name }} for offline POS use.
                    </h1>
                    <p class="mt-4 max-w-xl text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                        We’re downloading and installing your store data on this device. Large stores can take a few minutes on the first load. We’ll open the dashboard automatically when everything is ready.
                    </p>

                    <div class="mt-8 rounded-2xl border border-slate-200 bg-slate-50/90 p-5 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">Current phase</p>
                                <p data-bootstrap-headline class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">Downloading store data</p>
                            </div>
                            <p data-bootstrap-percent class="text-2xl font-semibold text-amber-600 dark:text-amber-400">0%</p>
                        </div>

                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                            <div data-bootstrap-bar class="h-full rounded-full bg-amber-500 transition-all duration-300" style="width: 0%"></div>
                        </div>

                        <p data-bootstrap-label class="mt-4 text-sm text-slate-600 dark:text-slate-300">Preparing store data download</p>
                    </div>
                </div>
            </div>

            <div class="p-8 sm:p-10">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">While we finish setup</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    The POS stays blocked during this first install so stock, sales, and dashboard calculations don’t open with partial data.
                </p>

                <dl class="mt-8 space-y-4 text-sm text-slate-600 dark:text-slate-300">
                    <div class="flex items-start gap-3">
                        <span class="mt-1 h-2 w-2 rounded-full bg-cyan-300"></span>
                        <dd>We download the cloud snapshot once, then install it into the local POS database.</dd>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-1 h-2 w-2 rounded-full bg-blue-300"></span>
                        <dd>After this finishes, normal sync switches to smaller delta updates instead of full redownloads.</dd>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-1 h-2 w-2 rounded-full bg-indigo-300"></span>
                        <dd>Local offline changes stay higher priority and will be pushed back to cloud later.</dd>
                    </div>
                </dl>

                <div data-bootstrap-error class="mt-8 hidden rounded-2xl border border-red-300 bg-red-50/80 p-5 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="text-sm font-semibold text-red-700 dark:text-red-200">Bootstrap needs attention</h3>
                    <p data-bootstrap-error-message class="mt-2 text-sm text-red-700 dark:text-red-200">Store download failed. Please retry sync.</p>
                    <div class="mt-4 flex gap-3">
                        <button type="button" data-bootstrap-retry class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                            Retry Download
                        </button>
                        <a href="{{ $syncLogUrl }}" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-400/30 dark:text-red-200 dark:hover:bg-red-500/10">
                            Open Sync Log
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
    (() => {
        const statusUrl = @json($syncStatusUrl);
        const dashboardUrl = @json($dashboardUrl);
        const retryUrl = @json($syncNowUrl);
        const csrfToken = @json(csrf_token());

        const headline = document.querySelector('[data-bootstrap-headline]');
        const percent = document.querySelector('[data-bootstrap-percent]');
        const bar = document.querySelector('[data-bootstrap-bar]');
        const label = document.querySelector('[data-bootstrap-label]');
        const errorPanel = document.querySelector('[data-bootstrap-error]');
        const errorMessage = document.querySelector('[data-bootstrap-error-message]');
        const retryButton = document.querySelector('[data-bootstrap-retry]');

        const phaseLabel = (bootstrapStatus) => bootstrapStatus === 'installing'
            ? 'Installing store data'
            : 'Downloading store data';

        const setError = (message) => {
            if (!errorPanel || !errorMessage) {
                return;
            }

            errorMessage.textContent = message;
            errorPanel.classList.remove('hidden');
        };

        const clearError = () => {
            if (!errorPanel) {
                return;
            }

            errorPanel.classList.add('hidden');
        };

        const renderStatus = (data) => {
            const bootstrapStatus = data?.bootstrap_status ?? 'downloading';
            const bootstrapPercent = Number(data?.bootstrap_progress_percent ?? 0);
            const bootstrapLabel = data?.bootstrap_progress_label ?? 'Preparing store data download';

            if (headline) {
                headline.textContent = phaseLabel(bootstrapStatus);
            }

            if (percent) {
                percent.textContent = `${bootstrapPercent}%`;
            }

            if (bar) {
                bar.style.width = `${bootstrapPercent}%`;
            }

            if (label) {
                label.textContent = bootstrapLabel;
            }

            if (bootstrapStatus === 'failed') {
                setError(bootstrapLabel || 'Store download failed. Please retry sync.');

                return;
            }

            clearError();

            if (bootstrapStatus === 'ready') {
                window.location.assign(dashboardUrl);
            }
        };

        const refreshStatus = async () => {
            try {
                const response = await fetch(statusUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json().catch(() => ({}));
                renderStatus(data);
            } catch (_error) {
            }
        };

        retryButton?.addEventListener('click', async () => {
            retryButton.setAttribute('disabled', 'disabled');

            try {
                const response = await fetch(retryUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({}),
                    credentials: 'same-origin',
                });

                if (response.ok) {
                    clearError();
                    await refreshStatus();
                }
            } catch (_error) {
            } finally {
                retryButton.removeAttribute('disabled');
            }
        });

        refreshStatus();
        window.setInterval(refreshStatus, 3000);
    })();
</script>
</body>
</html>
