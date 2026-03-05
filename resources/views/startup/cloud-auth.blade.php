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
                <div data-cloud-error class="mb-5 hidden rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"></div>

                @if ($errors->any())
                    <div class="mb-5 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200">
                        <p class="font-semibold">Unable to continue.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php
                    $initialMode = old('name') || old('password_confirmation') ? 'register' : 'login';
                @endphp

                <div class="mx-auto w-full max-w-xl">
                    <div class="mb-5 grid grid-cols-2 rounded-xl border border-slate-300 bg-white/80 p-1 dark:border-white/15 dark:bg-white/5">
                        <button
                            type="button"
                            data-auth-tab="login"
                            class="auth-tab rounded-lg px-3 py-2 text-sm font-semibold transition"
                        >
                            Login
                        </button>
                        <button
                            type="button"
                            data-auth-tab="register"
                            class="auth-tab rounded-lg px-3 py-2 text-sm font-semibold transition"
                        >
                            Register
                        </button>
                    </div>

                    <form method="POST" action="{{ route('startup.cloud.login') }}" data-auth-panel="login" class="space-y-4 rounded-2xl border border-slate-300 bg-white/80 p-5 dark:border-white/15 dark:bg-white/5">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Login to your cloud account</h2>
                        <input name="email" value="{{ old('email') }}" type="email" required placeholder="Email" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-blue-400/30 transition focus:border-blue-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-blue-300">
                        <input name="password" type="password" required placeholder="Password" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-blue-400/30 transition focus:border-blue-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-blue-300">
                        <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/30">
                            Login to Cloud
                        </button>
                    </form>

                    <form method="POST" action="{{ route('startup.cloud.register') }}" data-auth-panel="register" class="hidden space-y-4 rounded-2xl border border-slate-300 bg-white/80 p-5 dark:border-white/15 dark:bg-white/5">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Create cloud account</h2>
                        <input name="name" value="{{ old('name') }}" type="text" required placeholder="Full name" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-slate-400/30 transition focus:border-slate-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-slate-300">
                        <input name="email" value="{{ old('email') }}" type="email" required placeholder="Email" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-slate-400/30 transition focus:border-slate-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-slate-300">
                        <input name="password" type="password" required placeholder="Password" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-slate-400/30 transition focus:border-slate-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-slate-300">
                        <input name="password_confirmation" type="password" required placeholder="Confirm password" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-slate-400/30 transition focus:border-slate-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-slate-300">
                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-black focus:outline-none focus:ring-4 focus:ring-slate-500/30 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
                            Register on Cloud
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</main>
<script>
    (() => {
        const tabButtons = Array.from(document.querySelectorAll('[data-auth-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-auth-panel]'));
        const initialMode = @json($initialMode);
        const csrfToken = @json(csrf_token());
        const errorContainer = document.querySelector('[data-cloud-error]');

        const applyMode = (mode) => {
            tabButtons.forEach((button) => {
                const isActive = button.dataset.authTab === mode;
                button.classList.toggle('bg-blue-600', isActive);
                button.classList.toggle('text-white', isActive);
                button.classList.toggle('shadow-sm', isActive);
                button.classList.toggle('text-slate-600', !isActive);
                button.classList.toggle('dark:text-slate-300', !isActive);
            });

            panels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.authPanel !== mode);
            });
        };

        const hideError = () => {
            if (!errorContainer) {
                return;
            }

            errorContainer.classList.add('hidden');
            errorContainer.textContent = '';
        };

        const showError = (messages) => {
            if (!errorContainer) {
                return;
            }

            const normalized = Array.isArray(messages) ? messages : [messages];
            errorContainer.innerHTML = '';

            const title = document.createElement('p');
            title.className = 'font-semibold';
            title.textContent = 'Unable to continue.';
            errorContainer.appendChild(title);

            const list = document.createElement('ul');
            list.className = 'mt-2 list-disc space-y-1 pl-5';

            normalized.filter(Boolean).forEach((message) => {
                const li = document.createElement('li');
                li.textContent = String(message);
                list.appendChild(li);
            });

            errorContainer.appendChild(list);
            errorContainer.classList.remove('hidden');
        };

        const parseErrorMessages = (payload, fallback) => {
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

            return messages.length ? messages : [fallback];
        };

        const submitCloudForm = async (event) => {
            event.preventDefault();

            const form = event.currentTarget;
            const submitButton = form.querySelector('button[type=\"submit\"]');
            const fallbackMessage = 'Unable to connect to cloud server.';
            hideError();

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const payload = Object.fromEntries(new FormData(form).entries());
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    showError(parseErrorMessages(data, fallbackMessage));

                    return;
                }

                if (data.redirect) {
                    window.location.assign(data.redirect);

                    return;
                }

                showError(parseErrorMessages(data, 'Unexpected cloud response.'));
            } catch (error) {
                showError([error?.message || fallbackMessage]);
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        };

        tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                hideError();
                applyMode(button.dataset.authTab);
            });
        });

        panels.forEach((panel) => {
            panel.addEventListener('submit', submitCloudForm);
        });

        applyMode(initialMode === 'register' ? 'register' : 'login');
    })();
</script>
</body>
</html>
