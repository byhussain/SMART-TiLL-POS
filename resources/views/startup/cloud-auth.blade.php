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
                    <div class="rounded-2xl border border-slate-300 bg-white/80 p-5 dark:border-white/15 dark:bg-white/5">
                        <div class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50/80 px-6 text-center dark:border-white/15 dark:bg-slate-900/40">
                            <span class="text-lg font-semibold text-slate-700 dark:text-slate-100">Cloud Login Coming Soon</span>
                            <p class="mt-3 max-w-sm text-sm text-slate-500 dark:text-slate-300">
                                Desktop cloud authentication and store sync setup will be available in a future release.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>
</body>
</html>
