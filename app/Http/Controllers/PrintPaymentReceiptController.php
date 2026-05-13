<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SmartTill\Core\Models\Payment;

/**
 * Fallback receipt printer used when the SmartTill core package does not
 * register its own `print.payment` route. Extracted from a closure in
 * `routes/web.php` so the route table can be cached via `route:cache`.
 */
class PrintPaymentReceiptController extends Controller
{
    public function __invoke(Request $request, Payment $payment): Response
    {
        $next = urldecode((string) $request->query('next', '/'));

        return response()->view('print.payment', [
            'payment' => $payment->loadMissing([
                'payable',
                'store.currency',
                'store.timezone',
            ]),
            'next' => $next,
            'paper' => $request->query('paper'),
        ]);
    }
}
