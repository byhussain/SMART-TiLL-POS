<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Startup</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<main class="relative isolate min-h-screen overflow-hidden">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute left-[-10%] top-[-18%] h-[34rem] w-[34rem] rounded-full bg-cyan-500/20 blur-3xl dark:bg-cyan-500/20"></div>
        <div class="absolute bottom-[-22%] right-[-8%] h-[38rem] w-[38rem] rounded-full bg-blue-500/20 blur-3xl dark:bg-blue-500/20"></div>
    </div>

    <div class="mx-auto flex min-h-screen w-full max-w-6xl items-center px-6 py-10 lg:px-10">
        <section class="grid w-full overflow-hidden rounded-3xl border border-slate-300 bg-white/90 shadow-2xl shadow-slate-300/50 backdrop-blur dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/40 lg:grid-cols-[1.1fr_1fr]">
            <div class="relative overflow-hidden border-b border-slate-200 p-8 sm:p-10 dark:border-white/10 lg:border-b-0 lg:border-r">
                <div class="absolute inset-0 bg-gradient-to-br from-cyan-400/15 via-transparent to-blue-500/15 dark:from-cyan-400/10 dark:to-blue-500/10"></div>
                <div class="relative">
                    <p class="inline-flex items-center rounded-full border border-cyan-300/50 bg-cyan-100 px-3 py-1 text-xs font-medium uppercase tracking-[0.14em] text-cyan-700 dark:border-cyan-300/30 dark:bg-cyan-400/10 dark:text-cyan-100">
                        Smart Till POS
                    </p>
                    <h1 class="mt-5 text-3xl font-semibold leading-tight text-slate-900 dark:text-white sm:text-4xl">
                        Start your store in seconds.
                    </h1>
                    <p class="mt-4 max-w-xl text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                        Choose offline mode for quick local operations, or connect to cloud for account-based sync and centralized data.
                    </p>

                    <dl class="mt-8 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-cyan-300"></span>
                            <dd>Fast launch flow optimized for counter operations.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-blue-300"></span>
                            <dd>Cloud mode supports account login, store linking, and sync visibility.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-indigo-300"></span>
                            <dd>Secure local runtime with dashboard-first experience after onboarding.</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="p-8 sm:p-10">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">How do you want to continue?</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Select one option to continue setup on this device.</p>

                <div class="mt-8 grid gap-4">
                    <form method="POST" action="{{ route('startup.guest') }}">
                        @csrf
                        <button type="submit" class="group w-full rounded-2xl border border-slate-300 bg-white p-5 text-left transition hover:border-cyan-400/70 hover:bg-cyan-50 dark:border-white/15 dark:bg-white/5 dark:hover:border-cyan-300/60 dark:hover:bg-cyan-400/10">
                            <div class="flex items-center justify-between">
                                <span class="text-base font-semibold text-slate-900 dark:text-white">Continue as Guest</span>
                                <span class="rounded-lg border border-cyan-300/50 bg-cyan-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-cyan-700 dark:border-cyan-300/30 dark:bg-cyan-400/10 dark:text-cyan-100">Offline</span>
                            </div>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Use POS locally without cloud login. Ideal for standalone desktop usage.</p>
                        </button>
                    </form>

                    <a href="{{ route('startup.cloud.form') }}" class="group block w-full rounded-2xl border border-slate-300 bg-white p-5 text-left transition hover:border-blue-400/70 hover:bg-blue-50 dark:border-white/15 dark:bg-white/5 dark:hover:border-blue-300/60 dark:hover:bg-blue-400/10">
                        <div class="flex items-center justify-between">
                            <span class="text-base font-semibold text-slate-900 dark:text-white">Connect to Cloud</span>
                            <span class="rounded-lg border border-blue-300/50 bg-blue-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-blue-700 dark:border-blue-300/30 dark:bg-blue-400/10 dark:text-blue-100">Cloud</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Login with your cloud account to sync store data and manage operations centrally.</p>
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>
</body>
</html>
