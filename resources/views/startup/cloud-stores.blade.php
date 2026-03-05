<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Cloud Store</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<main class="mx-auto flex min-h-screen w-full max-w-3xl items-center justify-center px-6 py-10">
    <section class="w-full rounded-2xl border border-slate-300 bg-white p-8 shadow-xl dark:border-white/10 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Select Cloud Store</h1>
            <a href="{{ route('startup.cloud.form') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-300 dark:hover:text-blue-200">
                Back
            </a>
        </div>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Choose the cloud store to link with this POS device.</p>

        <div data-cloud-error class="mt-4 hidden rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"></div>

        <form data-store-form class="mt-6 space-y-4">
            @csrf
            <select name="server_store_id" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-white/15 dark:bg-white/5 dark:text-white">
                <option value="">Loading stores...</option>
            </select>

            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                Connect and Continue
            </button>
        </form>
    </section>
</main>
<script>
    (() => {
        const csrfToken = @json(csrf_token());
        const storesEndpoint = @json(route('startup.cloud.stores'));
        const selectStoreEndpoint = @json(route('startup.cloud.select-store'));
        const fallbackStores = @json($stores ?? []);
        const form = document.querySelector('[data-store-form]');
        const select = form?.querySelector('select[name="server_store_id"]');
        const submitButton = form?.querySelector('button[type="submit"]');
        const errorContainer = document.querySelector('[data-cloud-error]');

        const setError = (messages) => {
            if (!errorContainer) {
                return;
            }

            const list = Array.isArray(messages) ? messages : [messages];
            errorContainer.innerHTML = '';
            const title = document.createElement('p');
            title.className = 'font-semibold';
            title.textContent = 'Unable to continue.';
            errorContainer.appendChild(title);

            const ul = document.createElement('ul');
            ul.className = 'mt-2 list-disc space-y-1 pl-5';

            list.filter(Boolean).forEach((message) => {
                const item = document.createElement('li');
                item.textContent = String(message);
                ul.appendChild(item);
            });

            errorContainer.appendChild(ul);
            errorContainer.classList.remove('hidden');
        };

        const clearError = () => {
            if (!errorContainer) {
                return;
            }

            errorContainer.classList.add('hidden');
            errorContainer.textContent = '';
        };

        const parseMessages = (payload, fallback) => {
            if (!payload || typeof payload !== 'object') {
                return [fallback];
            }

            const messages = [];
            if (typeof payload.message === 'string' && payload.message.trim() !== '') {
                messages.push(payload.message.trim());
            }

            if (payload.errors && typeof payload.errors === 'object') {
                Object.values(payload.errors).forEach((value) => {
                    if (Array.isArray(value)) {
                        value.forEach((item) => {
                            if (typeof item === 'string' && item.trim() !== '') {
                                messages.push(item.trim());
                            }
                        });
                    } else if (typeof value === 'string' && value.trim() !== '') {
                        messages.push(value.trim());
                    }
                });
            }

            return messages.length > 0 ? messages : [fallback];
        };

        const renderStores = (stores) => {
            if (!select) {
                return;
            }

            select.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = stores.length > 0 ? 'Select store' : 'No stores found';
            select.appendChild(placeholder);

            stores.forEach((store) => {
                const option = document.createElement('option');
                option.value = String(store.id);
                option.textContent = store.name ?? `Store #${store.id}`;
                select.appendChild(option);
            });
        };

        const loadStores = async () => {
            if (!select) {
                return;
            }

            clearError();

            try {
                const response = await fetch(storesEndpoint, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    setError(parseMessages(payload, 'Unable to load cloud stores.'));
                    renderStores([]);

                    return;
                }

                const stores = Array.isArray(payload.data) ? payload.data : [];
                renderStores(stores);
            } catch (error) {
                if (Array.isArray(fallbackStores) && fallbackStores.length > 0) {
                    renderStores(fallbackStores);
                    return;
                }

                setError([error?.message || 'Unable to load cloud stores.']);
                renderStores([]);
            }
        };

        const submitStore = async (event) => {
            event.preventDefault();
            if (!form || !select) {
                return;
            }

            clearError();
            submitButton?.setAttribute('disabled', 'disabled');

            try {
                const response = await fetch(selectStoreEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        server_store_id: select.value,
                    }),
                });

                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    setError(parseMessages(payload, 'Unable to connect cloud store.'));
                    return;
                }

                if (payload.redirect) {
                    window.location.assign(payload.redirect);
                    return;
                }

                setError(parseMessages(payload, 'Unexpected cloud response.'));
            } catch (error) {
                setError([error?.message || 'Unable to connect cloud store.']);
            } finally {
                submitButton?.removeAttribute('disabled');
            }
        };

        form?.addEventListener('submit', submitStore);
        loadStores();
    })();
</script>
</body>
</html>
