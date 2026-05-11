<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloud Connection</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<main class="relative isolate min-h-screen overflow-hidden">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute left-[-10%] top-[-15%] h-[34rem] w-[34rem] rounded-full bg-blue-500/20 blur-3xl"></div>
        <div class="absolute bottom-[-22%] right-[-10%] h-[36rem] w-[36rem] rounded-full bg-cyan-500/20 blur-3xl"></div>
    </div>

    <div class="mx-auto flex min-h-screen w-full max-w-7xl items-center px-6 py-10 lg:px-10">
        <section class="grid w-full overflow-hidden rounded-3xl border border-slate-300 bg-white/90 shadow-2xl shadow-slate-300/50 backdrop-blur dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/40 lg:grid-cols-[1fr_1.25fr]">
            <div class="relative border-b border-slate-200 p-8 sm:p-10 dark:border-white/10 lg:border-b-0 lg:border-r">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-400/15 via-transparent to-cyan-500/15 dark:from-blue-400/10 dark:to-cyan-500/10"></div>
                <div class="relative">
                    <div class="flex items-center justify-between gap-3">
                        <a
                            href="{{ route('startup.index') }}"
                            class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 transition hover:text-slate-900 dark:text-slate-300 dark:hover:text-white"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M11.78 4.22a.75.75 0 0 1 0 1.06L7.06 10l4.72 4.72a.75.75 0 1 1-1.06 1.06l-5.25-5.25a.75.75 0 0 1 0-1.06l5.25-5.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
                            </svg>
                            <span>Back</span>
                        </a>
                        <p class="inline-flex items-center rounded-full border border-blue-300/50 bg-blue-100 px-3 py-1 text-xs font-medium uppercase tracking-[0.14em] text-blue-700 dark:border-blue-300/30 dark:bg-blue-400/10 dark:text-blue-100">
                            Cloud Access
                        </p>
                    </div>

                    <h1 class="mt-5 text-3xl font-semibold leading-tight text-slate-900 dark:text-white sm:text-4xl">
                        Connect POS to Cloud
                    </h1>
                    <p class="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                        Link this device with your SMART TiLL server account to enable account sync, cloud backup, and connected store workflows.
                    </p>
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        Server: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $baseUrl ?: 'Not configured' }}</span>
                    </p>

                    <dl class="mt-8 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-blue-300"></span>
                            <dd>Use your server URL to connect directly with your cloud instance.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-cyan-300"></span>
                            <dd>Login if you already have an account, or register a new cloud account.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-indigo-300"></span>
                            <dd>After authentication, you will select a cloud store and start syncing.</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="p-8 sm:p-10">
                <div class="mx-auto w-full max-w-xl">

                    {{-- Error container --}}
                    <div data-cloud-error class="mb-5 hidden rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300"></div>

                    {{-- Tab switcher --}}
                    <div class="flex rounded-xl border border-slate-200 bg-slate-100 p-1 dark:border-white/10 dark:bg-white/5">
                        <button
                            data-tab="login"
                            type="button"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition"
                            data-active-class="bg-white text-slate-900 shadow dark:bg-slate-800 dark:text-white"
                            data-inactive-class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            Login
                        </button>
                        <button
                            data-tab="register"
                            type="button"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition"
                            data-active-class="bg-white text-slate-900 shadow dark:bg-slate-800 dark:text-white"
                            data-inactive-class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            Register
                        </button>
                    </div>

                    {{-- Server URL field (only shown when not pre-configured) --}}
                    @if (! $baseUrl)
                        <div class="mt-5">
                            <label for="server_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Server URL</label>
                            <input
                                type="url"
                                id="server_url"
                                name="server_url"
                                placeholder="https://your-smart-till-server.com"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                    @endif

                    {{-- Login form --}}
                    <form data-form="login" class="mt-5 space-y-4">
                        @csrf
                        <div>
                            <label for="login_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                            <input
                                type="email"
                                id="login_email"
                                name="email"
                                autocomplete="email"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <div>
                            <label for="login_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                            <input
                                type="password"
                                id="login_password"
                                name="password"
                                autocomplete="current-password"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <button
                            type="submit"
                            data-submit
                            class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                        >
                            Login to Cloud
                        </button>
                    </form>

                    {{-- Register form --}}
                    <form data-form="register" class="mt-5 hidden space-y-4">
                        @csrf
                        <div>
                            <label for="register_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Full Name</label>
                            <input
                                type="text"
                                id="register_name"
                                name="name"
                                autocomplete="name"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <div>
                            <label for="register_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                            <input
                                type="email"
                                id="register_email"
                                name="email"
                                autocomplete="email"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <div>
                            <label for="register_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                            <input
                                type="password"
                                id="register_password"
                                name="password"
                                autocomplete="new-password"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <div>
                            <label for="register_password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Confirm Password</label>
                            <input
                                type="password"
                                id="register_password_confirmation"
                                name="password_confirmation"
                                autocomplete="new-password"
                                required
                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400"
                            />
                        </div>
                        <button
                            type="submit"
                            data-submit
                            class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                        >
                            Create Cloud Account
                        </button>
                    </form>

                </div>
            </div>
        </section>
    </div>
</main>
<script>
    (() => {
        const csrfToken = @json(csrf_token());
        const loginEndpoint = @json(route('startup.cloud.login'));
        const registerEndpoint = @json(route('startup.cloud.register'));

        const tabButtons = document.querySelectorAll('[data-tab]');
        const loginForm = document.querySelector('[data-form="login"]');
        const registerForm = document.querySelector('[data-form="register"]');
        const errorContainer = document.querySelector('[data-cloud-error]');
        const serverUrlInput = document.querySelector('#server_url');

        const setError = (messages) => {
            if (!errorContainer) return;
            const list = Array.isArray(messages) ? messages : [messages];
            errorContainer.innerHTML = '';
            const title = document.createElement('p');
            title.className = 'font-semibold';
            title.textContent = 'Unable to continue.';
            errorContainer.appendChild(title);
            const ul = document.createElement('ul');
            ul.className = 'mt-2 list-disc space-y-1 pl-5';
            list.filter(Boolean).forEach((msg) => {
                const li = document.createElement('li');
                li.textContent = String(msg);
                ul.appendChild(li);
            });
            errorContainer.appendChild(ul);
            errorContainer.classList.remove('hidden');
        };

        const clearError = () => {
            if (!errorContainer) return;
            errorContainer.classList.add('hidden');
            errorContainer.innerHTML = '';
        };

        const parseMessages = (payload, fallback) => {
            if (!payload || typeof payload !== 'object') return [fallback];
            const messages = [];
            if (typeof payload.message === 'string' && payload.message.trim()) {
                messages.push(payload.message.trim());
            }
            if (payload.errors && typeof payload.errors === 'object') {
                Object.values(payload.errors).forEach((value) => {
                    const items = Array.isArray(value) ? value : [value];
                    items.forEach((item) => {
                        if (typeof item === 'string' && item.trim()) messages.push(item.trim());
                    });
                });
            }
            return messages.length > 0 ? messages : [fallback];
        };

        const switchTab = (activeTab) => {
            tabButtons.forEach((btn) => {
                const isActive = btn.dataset.tab === activeTab;
                btn.className = 'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition ' +
                    (isActive ? btn.dataset.activeClass : btn.dataset.inactiveClass);
            });
            if (loginForm) loginForm.classList.toggle('hidden', activeTab !== 'login');
            if (registerForm) registerForm.classList.toggle('hidden', activeTab !== 'register');
            clearError();
        };

        tabButtons.forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });

        // Initialise active state
        switchTab('login');

        const submitForm = async (form, endpoint) => {
            clearError();
            const submitBtn = form.querySelector('[data-submit]');
            submitBtn?.setAttribute('disabled', 'disabled');

            const data = {};
            new FormData(form).forEach((value, key) => {
                if (key !== '_token') data[key] = value;
            });

            // Attach server URL from shared field when present
            if (serverUrlInput && serverUrlInput.value.trim()) {
                data.server_url = serverUrlInput.value.trim();
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(data),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    setError(parseMessages(payload, 'Authentication failed. Please try again.'));
                    return;
                }

                if (payload.redirect) {
                    window.location.assign(payload.redirect);
                    return;
                }

                setError(['Unexpected response from server.']);
            } catch (error) {
                setError([error?.message || 'Unable to connect to server.']);
            } finally {
                submitBtn?.removeAttribute('disabled');
            }
        };

        loginForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm(loginForm, loginEndpoint);
        });

        registerForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm(registerForm, registerEndpoint);
        });
    })();
</script>
</body>
</html>
