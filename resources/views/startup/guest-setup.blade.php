<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Store</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<main class="relative isolate min-h-screen overflow-hidden">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute left-[-12%] top-[-16%] h-[32rem] w-[32rem] rounded-full bg-cyan-500/20 blur-3xl"></div>
        <div class="absolute bottom-[-20%] right-[-10%] h-[34rem] w-[34rem] rounded-full bg-blue-500/20 blur-3xl"></div>
    </div>

    <div class="mx-auto flex min-h-screen w-full max-w-6xl items-center px-6 py-10 lg:px-10">
        <section class="grid w-full overflow-hidden rounded-3xl border border-slate-300 bg-white/90 shadow-2xl shadow-slate-300/50 backdrop-blur dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/40 lg:grid-cols-[1fr_1.25fr]">
            <div class="relative border-b border-slate-200 p-8 sm:p-10 dark:border-white/10 lg:border-b-0 lg:border-r">
                <div class="absolute inset-0 bg-gradient-to-br from-cyan-400/15 via-transparent to-blue-500/15 dark:from-cyan-400/10 dark:to-blue-500/10"></div>
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
                        <p class="inline-flex items-center rounded-full border border-cyan-300/50 bg-cyan-100 px-3 py-1 text-xs font-medium uppercase tracking-[0.14em] text-cyan-700 dark:border-cyan-300/30 dark:bg-cyan-400/10 dark:text-cyan-100">
                            Guest Setup
                        </p>
                    </div>
                    <h1 class="mt-5 text-3xl font-semibold leading-tight text-slate-900 dark:text-white sm:text-4xl">
                        Create Your Store
                    </h1>
                    <p class="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                        Configure your store identity and region defaults. These settings drive currency, timezone, and transaction behavior in POS.
                    </p>

                    <dl class="mt-8 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-cyan-300"></span>
                            <dd>Fast one-time setup for offline-first usage.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-blue-300"></span>
                            <dd>Region details can be adjusted later in store settings.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-indigo-300"></span>
                            <dd>Works in both guest mode and future cloud-connected mode.</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="p-8 sm:p-10">
                @if ($errors->any())
                    <div class="mb-5 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form id="guest-setup-form" method="POST" action="{{ route('startup.guest.setup.store') }}" class="grid gap-5 md:grid-cols-2">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Store name</label>
                        <input name="name" value="{{ old('name') }}" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-cyan-400/30 transition focus:border-cyan-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-cyan-300">
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Country</label>
                        <select id="country_id" name="country_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-cyan-400/30 transition focus:border-cyan-500 focus:ring-4 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-cyan-300">
                            <option value="">Select country</option>
                            @foreach ($countries as $country)
                                <option value="{{ $country->id }}" @selected((string) old('country_id') === (string) $country->id)>{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Currency</label>
                        <select id="currency_id" name="currency_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-cyan-400/30 transition focus:border-cyan-500 focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-cyan-300">
                            <option value="">Select currency</option>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}" @selected((string) old('currency_id') === (string) $currency->id)>{{ $currency->name }} ({{ $currency->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Timezone</label>
                        <select id="timezone_id" name="timezone_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none ring-cyan-400/30 transition focus:border-cyan-500 focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-cyan-300">
                            <option value="">Select timezone</option>
                            @foreach ($timezones as $timezone)
                                <option value="{{ $timezone->id }}" @selected((string) old('timezone_id') === (string) $timezone->id)>{{ $timezone->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-2 pt-2">
                        <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/30">
                            Create Store
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</main>
@php
    $currencyPayload = $currencies->map(function ($currency) {
        return [
            'id' => $currency->id,
            'name' => $currency->name,
            'code' => $currency->code,
            'country_id' => $currency->country_id,
        ];
    })->values();

    $timezonePayload = $timezones->map(function ($timezone) {
        return [
            'id' => $timezone->id,
            'name' => $timezone->name,
            'country_id' => $timezone->country_id,
        ];
    })->values();
@endphp
<script>
    (() => {
        const countrySelect = document.getElementById('country_id');
        const currencySelect = document.getElementById('currency_id');
        const timezoneSelect = document.getElementById('timezone_id');
        const form = document.getElementById('guest-setup-form');

        if (!countrySelect || !currencySelect || !timezoneSelect || !form) {
            return;
        }

        const currencies = @json($currencyPayload);
        const timezones = @json($timezonePayload);

        const oldCurrency = @json((string) old('currency_id'));
        const oldTimezone = @json((string) old('timezone_id'));

        const rebuildOptions = (select, options, labelBuilder, selectedValue) => {
            select.innerHTML = '';

            if (options.length === 0) {
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'No options available';
                select.appendChild(emptyOption);
                select.value = '';

                return;
            }

            options.forEach((option) => {
                const opt = document.createElement('option');
                opt.value = String(option.id);
                opt.textContent = labelBuilder(option);
                select.appendChild(opt);
            });

            const hasSelected = selectedValue && options.some((option) => String(option.id) === String(selectedValue));
            select.value = hasSelected ? String(selectedValue) : String(options[0].id);
        };

        const setLockedState = (select, isLocked) => {
            select.disabled = isLocked;
        };

        const applyCountrySelection = (countryId, preferredCurrency = '', preferredTimezone = '') => {
            if (!countryId) {
                currencySelect.innerHTML = '<option value="">Select currency</option>';
                timezoneSelect.innerHTML = '<option value="">Select timezone</option>';
                currencySelect.value = '';
                timezoneSelect.value = '';
                setLockedState(currencySelect, true);
                setLockedState(timezoneSelect, true);

                return;
            }

            const countryCurrencies = currencies.filter((currency) => String(currency.country_id) === String(countryId));
            const countryTimezones = timezones.filter((timezone) => String(timezone.country_id) === String(countryId));

            rebuildOptions(currencySelect, countryCurrencies, (currency) => `${currency.name} (${currency.code})`, preferredCurrency);
            rebuildOptions(timezoneSelect, countryTimezones, (timezone) => timezone.name, preferredTimezone);

            setLockedState(currencySelect, countryCurrencies.length <= 1);
            setLockedState(timezoneSelect, countryTimezones.length <= 1);
        };

        countrySelect.addEventListener('change', (event) => {
            applyCountrySelection(event.target.value);
        });

        form.addEventListener('submit', () => {
            currencySelect.disabled = false;
            timezoneSelect.disabled = false;
        });

        applyCountrySelection(countrySelect.value, oldCurrency, oldTimezone);
    })();
</script>
</body>
</html>
