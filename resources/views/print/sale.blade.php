<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $sale->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>

    @php
        $settingsService = app(\SmartTill\Core\Services\CoreStoreSettingsService::class);

        // Paper size configuration
        $receiptFormat = $sale->store
            ? $settingsService->getReceiptFormat($sale->store)
            : \SmartTill\Core\Enums\PrintOption::default()->value;
        $defaultPaper = \SmartTill\Core\Enums\PrintOption::tryFrom($receiptFormat)?->getWidth()
            ?? \SmartTill\Core\Enums\PrintOption::default()->getWidth();
        $paper = $paper ?? request('paper') ?? $defaultPaper;
        $allowed = ['210','148','80','58'];
        $paper = in_array($paper, $allowed) ? $paper : $defaultPaper;
        $printMargin = in_array($paper, ['210','148']) ? 8 : 4;

        // Navigation URL
        $next = $next ?? request('next') ?? '/';
        $next = ($next !== '/' && $next !== null) ? urldecode($next) : $next;
        $autoPrint = $autoPrint ?? false;

        // Number formatting functions
        // Format with decimals when present, removing trailing zeros (for qty, price, disc, line totals, subtotal, discounts)
        $fmtWithDecimals = function ($number) {
            if (! is_numeric($number)) {
                return '0';
            }

            $numberString = (string) $number;
            $decimalPosition = strpos($numberString, '.');

            if ($decimalPosition === false) {
                $decimals = 0;
            } else {
                $decimalPart = rtrim(substr($numberString, $decimalPosition + 1), '0');
                $decimals = strlen($decimalPart);
            }

            $decimals = min($decimals, 6);
            $formatted = number_format((float) $number, $decimals, '.', ',');
            // Remove trailing zeros and trailing decimal point if not needed (only after decimal point)
            if (strpos($formatted, '.') !== false) {
                $formatted = rtrim(rtrim($formatted, '0'), '.');
            }
            return $formatted ?: '0';
        };

        // Format quantity with up to 6 decimal places, removing trailing zeros
        $fmtQuantity = function ($number) {
            $value = is_numeric($number) ? (float) $number : 0;
            $formatted = number_format($value, 6, '.', ',');
            // Remove trailing zeros and trailing decimal point if not needed
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };

        // Format price (2 decimal places, removing trailing zeros)
        $fmt = function ($number) {
            $value = (float) $number;
            $formatted = number_format($value, 2, '.', ',');
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };

        $fmtRounded = function ($number) {
            return number_format(round((float) $number), 0, '.', ',');
        };

        // Receipt settings from store
        $showDecimals = $sale->store
            ? $settingsService->getShowDecimalsInReceiptTotal($sale->store)
            : true;
        $showDifferences = $sale->store
            ? $settingsService->getShowDifferencesInReceipt($sale->store)
            : false;


        // Get currency code from store
        $currencyCode = $sale->store?->currency?->code ?? 'PKR';

        // Conditional formatter ONLY for final Total Amount based on store settings
        $fmtTotal = function ($number) use ($showDecimals, $fmt, $fmtRounded) {
            if ($showDecimals) {
                return $fmt($number);
            }
            return $fmtRounded($number);
        };

        // Store-local invoice date/time
        $saleDate = ($sale->store && $sale->created_at)
            ? $sale->created_at->setTimezone($sale->store->timezone?->name ?? 'UTC')
            : $sale->created_at;

        // Paper size classes for Tailwind
        $paperClasses = [
            '210' => 'max-w-[210mm] text-sm',
            '148' => 'max-w-[148mm] text-sm',
            '80' => 'max-w-[80mm] text-xs',
            '58' => 'max-w-[58mm] text-xs',
        ];
        $paperClass = $paperClasses[$paper] ?? $paperClasses['210'];
        $isThermal = in_array($paper, ['80', '58'], true);
        $isTaxEnabled = $settingsService->isTaxEnabled($sale->store);
        $lineItemColumnCount = $isThermal ? 1 : ($isTaxEnabled ? 6 : 5);
        $metaGridClass = $isTaxEnabled
            ? 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]'
            : 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]';
        $descriptionWidthClass = $isThermal ? 'w-full' : 'w-[45%]';
        $headerLayoutClass = $isThermal ? 'flex flex-col gap-1 mb-2' : 'flex justify-between items-start gap-2 mb-1';
        $headerMetaClass = $isThermal ? 'text-left mt-1' : 'text-right shrink-0';
        $totalLabelClass = $isThermal ? 'text-sm leading-tight' : 'text-lg';
        $totalAmountClass = $isThermal ? 'text-base' : 'text-lg';

    @endphp

    @vite(['resources/css/app.css'])

    <style>
        /* Print-specific styles - optimize for professional printing */
        @media print {
            @page {
                size: {{ $paper }}mm auto;
                margin: {{ $printMargin }}mm {{ $printMargin }}mm {{ $printMargin + 10 }}mm {{ $printMargin }}mm;
            }

            .no-print {
                display: none !important;
            }

            /* Remove shadows and rounded corners for print */
            * {
                box-shadow: none !important;
                text-shadow: none !important;
                border-radius: 0 !important;
            }

            /* Customer info box - compact spacing */
            .customer-box {
                background: white !important;
                border: none !important;
                padding: 4px 0 !important;
                margin-bottom: 4px !important;
            }

            /* Totals box - compact spacing */
            .totals-box {
                background: white !important;
                border: none !important;
                padding: 4px 0 !important;
            }

            /* Final total - compact separator */
            .summary-final-total {
                border-top: 1px solid #ccc !important;
                background: white !important;
                font-weight: bold !important;
                padding-top: 6px !important;
                margin-top: 6px !important;
            }

            /* FBR sections - subtle border */
            .fbr-box {
                background: white !important;
                border: 1px solid #ccc !important;
                padding: 8px !important;
            }

            /* Table header - light background, compact */
            .receipt-table thead tr {
                background: #f1f5f9 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
                color: #000 !important;
            }

            /* Compact table cells */
            .receipt-table th,
            .receipt-table td {
                padding: 3px 4px !important;
            }

            /* Soft separators between table rows */
            .receipt-table tbody tr {
                border-bottom: 1px solid #e2e8f0 !important;
            }

            /* Remove border from last row */
            .receipt-table tbody tr:last-child {
            border-bottom: none !important;
        }

            /* Invoice badge - simple text, no border */
            .invoice-badge {
                background: white !important;
                border: none !important;
                color: #000 !important;
            }

            /* Remove light backgrounds that don't print well */
            .bg-slate-50,
            .bg-blue-50,
            .bg-red-50 {
                background: white !important;
            }

            /* Ensure text is dark and readable */
            body {
                color: #000 !important;
            }

            .text-slate-600,
            .text-slate-700 {
                color: #000 !important;
            }

            .text-blue-600,
            .text-blue-700 {
                color: #000 !important;
            }

            /* Remove all borders for clean print */
            .border-slate-200,
            .border-slate-300,
            .border-blue-300,
            .border-red-300 {
                border: none !important;
            }

            /* Page break controls */
            .summary-totals {
            page-break-inside: avoid;
        }

            .summary-final-total {
            page-break-inside: avoid;
            }

            /* Allow table rows to break across pages */
            .receipt-table tbody tr {
                page-break-inside: auto;
            }

            /* Keep last 3 rows together */
            .receipt-table tbody tr:nth-last-child(-n+3) {
                page-break-inside: avoid;
            }

            /* Table header repeat */
            .receipt-table thead {
                display: table-header-group;
                page-break-inside: avoid;
            }

            body.paper-210 .item-meta,
            body.paper-148 .item-meta {
                display: none;
            }
        }
    </style>
</head>
<body class="paper-{{ $paper }} {{ $paperClass }} mx-auto p-1 font-sans text-slate-800 bg-white antialiased">

    <!-- Receipt Container -->
<div class="print-container">

        <!-- Header Section: Store Info & Invoice Meta -->
        <div class="{{ $headerLayoutClass }}">
            <!-- Store Information -->
        @if($sale->store)
                <div class="flex-[1.2] min-w-0">
                    <div class="text-2xl font-bold text-slate-900 mb-0.5 leading-tight tracking-tight">{{ $sale->store->name }}</div>
                @if($sale->store->address)
                        <div class="text-xs text-slate-600 leading-tight mb-0.5">{{ $sale->store->address }}</div>
                @endif
                    <div class="text-xs text-slate-600 leading-tight">
                    @if($sale->store->phone)
                            <div class="inline-block mr-2">
                                <span class="text-slate-500">Phone:</span>
                                <span class="ml-1">{{ $sale->store->phone }}</span>
                            </div>
                    @endif
                    @if($sale->store->email)
                            <div class="inline-block">
                                <span class="text-slate-500">Email:</span>
                                <span class="ml-1 whitespace-nowrap">{{ $sale->store->email }}</span>
                            </div>
                    @endif
                </div>
            </div>
        @endif

            <!-- Invoice Meta Information -->
            <div class="flex-[0.8] {{ $headerMetaClass }}">
                <div class="{{ $isThermal ? 'text-base' : 'text-xl' }} font-light text-slate-400 mb-0.5 tracking-wider uppercase">Invoice</div>
                <div class="text-xs text-slate-700 {{ $isThermal ? 'text-left' : 'text-right' }}">
                    <div class="{{ $isThermal ? 'text-left' : 'text-right' }}">
                        <span class="text-slate-500">Invoice:</span>
                        <span class="ml-1 font-semibold text-slate-900">#{{ $sale->reference }}</span>
                    </div>
                    <div class="{{ $isThermal ? 'text-left' : 'text-right' }}">
                        <span class="text-slate-500">Date:</span>
                        <span class="ml-1">{{ optional($saleDate)->format('d-m-Y h:i A') }}</span>
                    </div>
                    <div class="{{ $isThermal ? 'text-left' : 'text-right' }}">
                        <span class="text-slate-500">Payment:</span>
                        <span class="ml-1 font-medium">{{ $sale->payment_status?->name ?? 'Pending' }}</span>
                    </div>
                    @php
                        $paymentStatusValue = $sale->payment_status instanceof \SmartTill\Core\Enums\SalePaymentStatus
                            ? $sale->payment_status->value
                            : (is_string($sale->payment_status) ? $sale->payment_status : null);
                    @endphp
                    @if($paymentStatusValue === \SmartTill\Core\Enums\SalePaymentStatus::Paid->value)
                        <div class="{{ $isThermal ? 'text-left' : 'text-right' }}">
                            <span class="text-slate-500">Method:</span>
                            <span class="ml-1 font-medium">{{ $sale->payment_method?->getLabel() ?? '-' }}</span>
                        </div>
                    @endif
            @if($sale->use_fbr)
                @php
                    $posId = $sale->store->fbr_environment === \SmartTill\Core\Enums\FbrEnvironment::SANDBOX
                        ? $sale->store->fbr_sandbox_pos_id
                        : $sale->store->fbr_pos_id;
                @endphp
                @if($posId)
                            <div class="{{ $isThermal ? 'text-left' : 'text-right' }}">
                                <span class="text-slate-500">POS ID:</span>
                                <span class="ml-1">{{ $posId }}</span>
                            </div>
                @endif
            @endif
                </div>
        </div>
    </div>

        <!-- Customer Information Section (Only show if customer exists) -->
                @if($sale->customer)
            <div class="customer-box mb-1">
                <div class="text-xs font-medium text-slate-500 mb-0.5 uppercase tracking-wider">Bill To</div>
                <div class="text-xs text-slate-700 leading-tight">
                    <div class="font-bold text-slate-900 text-sm">{{ $sale->customer->name }}</div>
                    @if($sale->customer->phone)
                        <div>
                            <span class="text-slate-500">Phone:</span>
                            <span class="ml-1">{{ $sale->customer->phone }}</span>
                        </div>
                    @endif
                    @if($sale->customer->email)
                        <div>
                            <span class="text-slate-500">Email:</span>
                            <span class="ml-1">{{ $sale->customer->email }}</span>
                        </div>
                    @endif
                    @if($sale->customer->address)
                        <div>
                            <span class="text-slate-500">Address:</span>
                            <span class="ml-1">{{ $sale->customer->address }}</span>
                        </div>
                @endif
            </div>
        </div>
        @endif

        @if($sale->note)
            <div class="customer-box mb-1">
                <div class="text-xs font-bold text-slate-700 mb-0.5 uppercase tracking-wider">Note</div>
                <div class="text-xs text-slate-700 leading-tight">
                    <span class="font-bold text-slate-700">{{ $sale->note }}</span>
                </div>
            </div>
        @endif

        <!-- Items Table -->
        <table class="receipt-table w-full border-collapse mb-1 mt-2 text-xs">
        <thead>
                <tr class="bg-slate-50 text-slate-700">
                    <th class="px-1 py-1 text-left font-semibold uppercase tracking-wider text-xs {{ $descriptionWidthClass }}">Description</th>
                    @if (! $isThermal)
                        <th class="qty px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs w-[10%]">Qty</th>
                        <th class="price px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs w-[10%]">Price</th>
                        <th class="disc px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs w-[15%]">Disc</th>
                        @if($isTaxEnabled)
                            <th class="tax px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs w-[15%]">Tax</th>
                        @endif
                        <th class="total px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs w-[10%]">Total</th>
                    @endif
        </tr>
            @if ($isThermal)
                <tr class="bg-slate-50 text-slate-700">
                    <th class="px-1 py-1 w-full" colspan="{{ $lineItemColumnCount }}">
                        <div class="{{ $metaGridClass }} w-full gap-1 text-xs uppercase text-slate-700 font-semibold">
                            <span class="text-left">Qty</span>
                            <span class="text-center">Price</span>
                            <span class="text-center">Disc</span>
                            @if($isTaxEnabled)
                                <span class="text-center">Tax</span>
                            @endif
                            <span class="text-right">Total</span>
                        </div>
                    </th>
                </tr>
            @endif
        </thead>
        <tbody>
                @php
                    $discountTotal = 0;
                    $rawSubtotal = 0;
                    $taxTotal = 0;
                @endphp

        @foreach($groupedVariations as $line)
                @php
                    $variation = $line['variation'];
                    $qty = (float) ($line['quantity'] ?? 0);
                    $unitPrice = (float) ($line['unit_price'] ?? 0);
                    $lineDiscountAmount = (float) ($line['line_discount'] ?? 0);
                    $lineDiscountType = $line['discount_type'] ?? null;
                    $lineDiscountPercentage = $line['discount_percentage'] ?? null;
                    $lineTotalTax = (float) ($line['line_tax_total'] ?? 0);
                    $lineTaxAmount = $qty != 0 ? ($lineTotalTax / $qty) : 0;
                    $lineTotal = (float) ($line['line_total'] ?? 0);

                    // Calculate subtotal BEFORE discount
                    // For preparable variations, unit_price is now calculated before discount
                    // For regular variations, unit_price is before discount
                    // So for both, subtotal = qty * unit_price
                    $lineSubtotal = $qty * $unitPrice;

                    $discountTotal += $lineDiscountAmount;
                    $taxTotal += $lineTotalTax;
                    $rawSubtotal += $lineSubtotal;

                    // Calculate price excluding tax if taxes are enabled
                    $displayPrice = $isTaxEnabled ? ($unitPrice - $lineTaxAmount) : $unitPrice;

                        // Format tax display
                    $taxPercentage = $lineSubtotal > 0 ? ($lineTotalTax / $lineSubtotal) * 100 : 0;
                    $taxPercentLabel = rtrim(rtrim(number_format($taxPercentage, 6, '.', ''), '0'), '.') ?: '0';
                    $taxDisplay = $lineTotalTax > 0 ? $taxPercentLabel.'% '.$fmtWithDecimals($lineTotalTax) : '—';

                        // Format discount display
                    $discountDisplay = '-';
                    if ($lineDiscountAmount != 0) {
                        if ($lineDiscountType === 'percentage' && $lineDiscountPercentage !== null) {
                            $discountDisplay = $fmtWithDecimals($lineDiscountPercentage) . '%';
                        } else {
                            $discountDisplay = $fmtWithDecimals($lineDiscountAmount);
                        }
                    }

                    $lineDescription = $line['description'] ?? $variation?->pivot?->description ?? 'Item';
                    $unitSymbol = $variation?->unit?->symbol;
                    $qtyDisplay = $fmtQuantity($qty).($unitSymbol ? ' '.$unitSymbol : '');
                @endphp

                    <tr class="border-b border-slate-200 hover:bg-slate-50 transition-colors">
                        <td class="px-1 py-1 align-top">
                            <div class="font-medium text-slate-900 text-xs">
                                {{ $lineDescription }}
                            </div>
                            @if ($isThermal)
                                <!-- Meta row for thermal paper -->
                                <div class="item-meta {{ $metaGridClass }} w-full gap-1 mt-0.5 text-xs text-slate-600 font-medium">
                                    <span class="text-left">{{ $qtyDisplay }}</span>
                                    <span class="text-center">{{ $fmtWithDecimals($displayPrice) }}</span>
                                    <span class="text-center">{!! $discountDisplay !!}</span>
                                    @if($isTaxEnabled)
                                        <span class="text-center">{!! $taxDisplay !!}</span>
                                    @endif
                                    <span class="text-right">{{ $fmtWithDecimals($line['line_total']) }}</span>
                                </div>
                            @endif
                        </td>
                        @if (! $isThermal)
                            <td class="qty px-1 py-1 text-right align-top font-medium text-slate-800 text-xs">{{ $qtyDisplay }}</td>
                            <td class="price px-1 py-1 text-center align-top text-slate-700 text-xs">{{ $fmtWithDecimals($displayPrice) }}</td>
                            <td class="disc px-1 py-1 text-center align-top text-slate-600 text-xs">{!! $discountDisplay !!}</td>
                            @if($isTaxEnabled)
                                <td class="tax px-1 py-1 text-center align-top text-slate-600 text-xs">{!! $taxDisplay !!}</td>
                            @endif
                            <td class="total px-1 py-1 text-right align-top font-semibold text-slate-900 text-xs">{{ $fmtWithDecimals($line['line_total']) }}</td>
                        @endif
            </tr>
        @endforeach
        </tbody>
    </table>

        <!-- Summary Section: Totals -->
    <div class="summary-section">
            <div class="totals-box summary-totals ml-auto w-auto text-xs p-1" id="totals-section">
            @php
                $saleDiscountAmount = (float) ($sale->discount ?? 0);
                $totalDiscount = $discountTotal + $saleDiscountAmount;
                    $freightFare = (float) ($sale->freight_fare ?? 0);
                $fbrServiceFee = 1.00;

                    // Calculate subtotal and final total from items
                if ($isTaxEnabled) {
                    $displaySubtotal = $rawSubtotal - $taxTotal - $totalDiscount;
                    $finalTotal = $displaySubtotal + $taxTotal + $freightFare;
                    if ($sale->use_fbr) {
                        $finalTotal += $fbrServiceFee;
                    }
                } else {
                    $displaySubtotal = $rawSubtotal;
                    $finalTotal = $displaySubtotal - $totalDiscount + $freightFare;
                }
            @endphp

                <!-- Subtotal -->
                <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                    <span class="text-slate-600">Subtotal:</span>
                    <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmtWithDecimals($displaySubtotal) }} {{ $currencyCode }}</span>
            </div>

                <!-- Discount on Items (line-item discounts only) -->
                @if($discountTotal != 0)
                    <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                        <span class="text-slate-600">Discount on Items:</span>
                        <span class="whitespace-nowrap font-semibold text-red-600">{{ $discountTotal < 0 ? '' : '-' }}{{ $fmtWithDecimals(abs($discountTotal)) }} {{ $currencyCode }}</span>
                </div>
            @endif

                <!-- Additional Discount (sale-level discount) -->
                @if($saleDiscountAmount != 0)
                    <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                        <span class="text-slate-600">Additional Discount:</span>
                        <span class="whitespace-nowrap font-semibold text-red-600">{{ $saleDiscountAmount < 0 ? '' : '-' }}{{ $fmtWithDecimals(abs($saleDiscountAmount)) }} {{ $currencyCode }}</span>
                </div>
            @endif

                <!-- Tax (only if taxes enabled) -->
            @if($isTaxEnabled && $taxTotal > 0)
                    <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                        <span class="text-slate-600">Tax:</span>
                        <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmtWithDecimals($taxTotal) }} {{ $currencyCode }}</span>
                    </div>
                @endif

                <!-- Freight Fare -->
                @if($freightFare > 0)
                    <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                        <span class="text-slate-600">Freight Fare:</span>
                        <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmtWithDecimals($freightFare) }} {{ $currencyCode }}</span>
                </div>
            @endif

                <!-- FBR Service Fee -->
            @if($sale->use_fbr)
                    <div class="flex justify-between gap-4 mb-0.5 text-slate-700">
                        <span class="text-slate-600">FBR POS Service Fee:</span>
                        <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmtWithDecimals($fbrServiceFee) }} {{ $currencyCode }}</span>
                </div>
            @endif

                <!-- Difference (shown when decimals are hidden and setting is enabled) -->
                @if(!$showDecimals && $showDifferences)
                    @php
                        $roundedTotal = round($finalTotal);
                        $actualTotal = $finalTotal;
                        $difference = $roundedTotal - $actualTotal;
                    @endphp
                    @if(abs($difference) > 0.001)
                        <div class="flex justify-between gap-4 mb-0.5 text-slate-500 text-xs">
                            <span class="text-slate-500">Difference:</span>
                            <span class="whitespace-nowrap">{{ $difference > 0 ? '+' : '' }}{{ $fmt($difference) }} {{ $currencyCode }}</span>
                        </div>
                    @endif
                @endif

                <!-- Total Amount -->
                <div class="summary-final-total flex justify-between gap-4 mt-1 pt-1 font-bold {{ $totalLabelClass }} text-slate-900">
                <span>Total Amount:</span>
                    <span class="whitespace-nowrap text-blue-600 {{ $totalAmountClass }}">{{ $fmtTotal($finalTotal) }} {{ $currencyCode }}</span>
            </div>
        </div>

            <!-- Thank You Message (Hidden on multi-page) -->
            <div class="text-center mt-1 mb-2 text-sm font-light text-slate-500 tracking-wide" id="thankyou-section">
                Thank you for your business!
        </div>

            <!-- FBR Invoice Section -->
        @if($sale->fbr_invoice_number)
                <div class="fbr-box text-center my-1 p-2 max-w-[220px] mx-auto">
                    <div class="text-xs font-bold text-blue-700 mb-0.5 uppercase tracking-wide">FBR INVOICE</div>
                    <div class="text-xs text-slate-700 mb-1 font-medium">Invoice #: {{ $sale->fbr_invoice_number }}</div>
                    <div class="flex justify-center items-center gap-2 mt-1">
                        <img src="{{ asset('images/fbr-pos.png') }}" alt="FBR POS" class="max-w-[40px] max-h-[30px] object-contain">
                    @if($sale->fbr_qr_code)
                            <div class="inline-block">{!! $sale->fbr_qr_code !!}</div>
                    @endif
                </div>
            </div>
        @endif

            <!-- FBR Refund Invoice Section -->
        @if($sale->fbr_refund_invoice_number)
                <div class="fbr-box text-center my-1 p-2 max-w-[220px] mx-auto">
                    <div class="text-xs font-bold text-red-700 mb-0.5 uppercase tracking-wide">FBR REFUND INVOICE</div>
                    <div class="text-xs text-slate-700 mb-1 font-medium">Refund Invoice #: {{ $sale->fbr_refund_invoice_number }}</div>
                    <div class="flex justify-center items-center gap-2 mt-1">
                        <img src="{{ asset('images/fbr-pos.png') }}" alt="FBR POS" class="max-w-[40px] max-h-[30px] object-contain">
                    @if($sale->fbr_refund_qr_code)
                            <div class="inline-block">{!! $sale->fbr_refund_qr_code !!}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

    <!-- JavaScript -->
<script>
    const nextUrl = @json($next ?? '/');
    const autoPrint = @json($autoPrint);
    let printInitiated = false;
    let printDialogRequested = false;
    let redirected = false;
    let redirectTimer = null;

    if (autoPrint && window.location.search) {
        window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
    }

    function goNext() {
        if (redirected) return;
        redirected = true;
        location.replace(nextUrl);
    }

    function scheduleReturn(delay = 250) {
        if (redirected) return;
        if (redirectTimer) {
            clearTimeout(redirectTimer);
        }

        redirectTimer = setTimeout(goNext, delay);
    }

        function hideNotesIfMultiPage() {
            const printContainer = document.querySelector('.print-container');
            if (!printContainer) return;

            // Get page height based on paper size
            const paperSize = document.body.className.match(/paper-(\d+)/);
            const paperWidth = paperSize ? parseInt(paperSize[1]) : 210;

            // Page height in mm
            let pageHeight = 297; // A4 default
            if (paperWidth === 148) pageHeight = 210; // A5

            // Convert to pixels (96 DPI: 1mm = 3.779527559 pixels)
            const pageHeightPx = pageHeight * 3.779527559;
            const contentHeight = printContainer.scrollHeight;

            // Hide thank you message if content exceeds 90% of page height
            if (contentHeight > pageHeightPx * 0.9) {
                const thankyouSection = document.querySelector('#thankyou-section');
                if (thankyouSection) thankyouSection.style.display = 'none';
            }
    }

    function triggerPrint() {
        if (printInitiated) return;
        printInitiated = true;
        document.documentElement.classList.add('printing');

            setTimeout(() => {
                hideNotesIfMultiPage();
            }, 100);

        setTimeout(() => {
            printDialogRequested = true;

            try {
                window.print();
            } catch (e) {
                    console.error('Print error:', e);
                    scheduleReturn(0);
            }
            }, 150);

        setTimeout(() => scheduleReturn(0), 8000);
    }

    function prepareAndPrint() {
        document.documentElement.classList.add('printing');
            hideNotesIfMultiPage();
        triggerPrint();
    }

        // Event listeners
    window.addEventListener('beforeprint', () => {
        document.documentElement.classList.add('printing');
            hideNotesIfMultiPage();
    });

    window.addEventListener('afterprint', () => {
        document.documentElement.classList.remove('printing');
        scheduleReturn(150);
    });

    window.addEventListener('focus', () => {
        if (printDialogRequested) {
            scheduleReturn(250);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (printDialogRequested && document.visibilityState === 'visible') {
            scheduleReturn(250);
        }
    });

        if (autoPrint) {
            // Auto-print on load
            window.addEventListener('load', () => {
                setTimeout(hideNotesIfMultiPage, 200);
                triggerPrint();
            });
        }
</script>
</body>
</html>
