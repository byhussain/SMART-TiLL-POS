<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt #{{ $payment->reference ?? $payment->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>

    @php
        $defaultPaper = $payment->store?->default_print_option?->getWidth() ?? '210';
        $paper = $paper ?? request('paper') ?? $defaultPaper;
        $allowed = ['210', '148', '80', '58'];
        $paper = in_array($paper, $allowed, true) ? $paper : $defaultPaper;
        $printMargin = in_array($paper, ['210', '148'], true) ? 8 : 4;

        $next = $next ?? request('next') ?? '/';
        $next = ($next !== '/' && $next !== null) ? urldecode($next) : $next;

        $store = $payment->store;
        $currency = $store?->currency;
        $currencyCode = $currency?->code ?? 'PKR';
        $currencyDecimals = $currency?->decimal_places ?? 2;

        $fmt = function ($number) use ($currencyDecimals) {
            $value = (float) $number;
            $formatted = number_format($value, $currencyDecimals, '.', ',');
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };

        $paymentDate = ($store && $payment->created_at)
            ? $payment->created_at->setTimezone($store->timezone?->name ?? 'UTC')
            : $payment->created_at;

        $paperClasses = [
            '210' => 'max-w-[210mm] text-sm',
            '148' => 'max-w-[148mm] text-sm',
            '80' => 'max-w-[80mm] text-xs',
            '58' => 'max-w-[58mm] text-xs',
        ];
        $paperClass = $paperClasses[$paper] ?? $paperClasses['210'];
        $isThermal = in_array($paper, ['80', '58'], true);

        $headerLayoutClass = $isThermal ? 'flex flex-col gap-1 mb-2' : 'flex justify-between items-start gap-2 mb-1';
        $headerMetaClass = $isThermal ? 'text-left mt-1' : 'text-right shrink-0';
        $detailsGridClass = $isThermal ? 'grid gap-2' : 'grid grid-cols-2 gap-4';
        $sectionClass = $isThermal ? 'border border-slate-200 rounded-md p-2' : 'border border-slate-200 rounded-lg p-4';
        $sectionTitleClass = $isThermal ? 'text-xs font-semibold text-slate-500 uppercase tracking-wider' : 'text-xs font-semibold text-slate-500 uppercase tracking-wider';
    @endphp

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page {
                size: {{ $paper }}mm auto;
                margin: {{ $printMargin }}mm;
            }

            body {
                margin: 0 !important;
                background: white !important;
                color: #000 !important;
            }

            .no-print {
                display: none !important;
            }

            * {
                box-shadow: none !important;
                text-shadow: none !important;
                border-radius: 0 !important;
            }

            .section-box {
                background: white !important;
                border: none !important;
                padding: 4px 0 !important;
            }
        }
    </style>
</head>
<body class="paper-{{ $paper }} {{ $paperClass }} mx-auto p-1 font-sans text-slate-800 bg-white antialiased">
    <div class="no-print flex items-center justify-center gap-3 mb-4 bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex-wrap">
        <button type="button" onclick="prepareAndPrint()" class="inline-flex items-center justify-center rounded-md bg-slate-900 text-white px-5 py-2 text-sm font-semibold shadow">Print</button>
        <a href="{{ $next }}" class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-900 px-5 py-2 text-sm font-semibold">Back</a>
    </div>

    <div class="print-container flex flex-col gap-2">
        <div class="{{ $headerLayoutClass }}">
            <div class="flex-[1.2] min-w-0">
                <div class="text-2xl font-bold text-slate-900 mb-0.5 leading-tight tracking-tight">{{ $store?->business_name ?? $store?->name ?? 'Store' }}</div>
                @if($store?->address)
                    <div class="text-xs text-slate-600 leading-tight mb-0.5">{{ $store->address }}</div>
                @endif
                <div class="text-xs text-slate-600 leading-tight">
                    @if($store?->phone)
                        <div class="inline-block mr-2">
                            <span class="text-slate-500">Phone:</span>
                            <span class="ml-1">{{ $store->phone }}</span>
                        </div>
                    @endif
                    @if($store?->email)
                        <div class="inline-block">
                            <span class="text-slate-500">Email:</span>
                            <span class="ml-1 whitespace-nowrap">{{ $store->email }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex-[0.8] {{ $headerMetaClass }}">
                <div class="{{ $isThermal ? 'text-base' : 'text-xl' }} font-light text-slate-400 mb-0.5 tracking-wider uppercase">Payment Receipt</div>
                <div class="text-xs text-slate-700 {{ $isThermal ? 'text-left' : 'text-right' }}">
                    <div>
                        <span class="text-slate-500">Receipt:</span>
                        <span class="ml-1 font-semibold text-slate-900">#{{ $payment->reference ?? $payment->id }}</span>
                    </div>
                    <div>
                        <span class="text-slate-500">Date:</span>
                        <span class="ml-1">{{ $paymentDate?->format('d-m-Y h:i A') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="{{ $sectionClass }}">
            <div class="{{ $sectionTitleClass }}">Receipt Details</div>
            <div class="{{ $detailsGridClass }} text-xs text-slate-700 mt-2">
                <div class="flex flex-col gap-1">
                    <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Payable</div>
                    <div class="text-sm font-semibold text-slate-900">{{ $payment->payable?->name ?? '—' }}</div>
                    @if ($payment->payable_type)
                        <div class="text-xs text-slate-500">{{ class_basename($payment->payable_type) }}</div>
                    @endif
                    @if ($payment->payable?->phone)
                        <div class="text-xs text-slate-500">{{ $payment->payable->phone }}</div>
                    @endif
                </div>

                <div class="flex flex-col gap-1">
                    <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Payment</div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Method:</span>
                        <span class="font-medium text-slate-900">{{ $payment->payment_method?->getLabel() ?? ucfirst((string) $payment->payment_method) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="{{ $sectionClass }}">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Paid</span>
                <span class="text-lg font-bold text-slate-900">{{ $fmt($payment->amount ?? 0) }} {{ $currencyCode }}</span>
            </div>
        </div>

        @if ($payment->note)
            <div class="{{ $sectionClass }}">
                <div class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-0.5">Note</div>
                <div class="text-xs text-slate-700 leading-tight">{{ $payment->note }}</div>
            </div>
        @endif
    </div>

    <script>
        const nextUrl = @json($next ?? '/');
        let printInitiated = false;
        let redirected = false;

        function goNext() {
            if (redirected) return;
            redirected = true;
            location.replace(nextUrl);
        }

        function triggerPrint() {
            if (printInitiated) return;
            printInitiated = true;
            document.documentElement.classList.add('printing');
            setTimeout(() => {
                try {
                    window.print();
                } catch (e) {
                    console.error('Print error:', e);
                }
            }, 150);
            setTimeout(goNext, 4000);
        }

        function prepareAndPrint() {
            document.documentElement.classList.add('printing');
            triggerPrint();
        }

        window.addEventListener('beforeprint', () => {
            document.documentElement.classList.add('printing');
        });

        window.addEventListener('afterprint', () => {
            document.documentElement.classList.remove('printing');
            goNext();
        });

        window.addEventListener('load', () => {
            triggerPrint();
        });
    </script>
</body>
</html>
