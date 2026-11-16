<?php

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Services\Mpesa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/lessons', function () {
    return Lesson::query()->latest()->paginate(20);
});

Route::post('/lessons', function (Request $request) {
    $data = $request->validate([
        'title' => ['required', 'string', 'max:200'],
        'level' => ['required', 'in:beginner,intermediate,advanced'],
    ]);

    $lesson = Lesson::create($data);

    return response()->json($lesson, 201);
});

Route::get('/lessons/{lesson}', function (Lesson $lesson) {
    return $lesson;
});

// Demo only: a real shop takes the amount from its own order, never from the request, and needs sign in.
Route::post('/payments', function (Request $request, Mpesa $mpesa) {
    $data = $request->validate([
        'phone' => ['required', 'string', 'regex:/^(?:\+?254|0)(7|1)\d{8}$/'],
        'amount' => ['required', 'integer', 'min:1', 'max:250000'],
    ]);
    $phone = '254'.substr(preg_replace('/\D/', '', $data['phone']), -9);

    try {
        $mpesa->ready();
    } catch (RuntimeException|HttpClientException $e) {
        Log::error('M-Pesa is not ready', ['error' => $e::class]);

        return response()->json(['message' => 'Payments are not available right now. Nothing was charged.'], 503);
    }

    $phoneKey = 'mpesa-phone:'.$phone;
    if (RateLimiter::tooManyAttempts($phoneKey, 3)) {
        return response()->json(['message' => 'Too many payment prompts for this phone number. Please wait a few minutes.'], 429);
    }

    $payment = Payment::create(['phone' => $phone, 'amount' => $data['amount'], 'status' => 'pending']);
    RateLimiter::hit($phoneKey, 600);

    try {
        $response = $mpesa->stkPush($phone, $payment->amount, 'ORDER'.$payment->id);
    } catch (ConnectionException) {
        $response = null;
    }

    if ($response && $mpesa->accepted($response)) {
        Payment::whereKey($payment->id)->where('status', 'pending')->update([
            'merchant_request_id' => $response->json('MerchantRequestID'),
            'checkout_request_id' => $response->json('CheckoutRequestID'),
        ]);

        return response()->json(['payment' => $payment->id, 'message' => 'Check your phone and enter your M-Pesa PIN.'], 202);
    }

    if ($response && $mpesa->refused($response)) {
        Payment::whereKey($payment->id)->where('status', 'pending')->update([
            'status' => 'failed',
            'result_desc' => mb_substr((string) ($response->json('errorMessage') ?? $response->json('ResponseDescription')), 0, 255),
        ]);

        return response()->json(['message' => 'The payment could not be started. You can try again.'], 502);
    }

    // No clear answer: the prompt may have been sent, so the payment stays pending for a person to check.
    Payment::whereKey($payment->id)->where('status', 'pending')->update(['result_desc' => 'No clear answer from Daraja: check manually']);

    return response()->json(['message' => 'We could not confirm the payment request. Please do not pay again; we will check it and update you.'], 504);
})->middleware('throttle:payments');

Route::post('/payments/result/{token}', function (Request $request, Mpesa $mpesa, string $token) {
    abort_unless(hash_equals($mpesa->callbackToken(), $token), 404);
    $ack = response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

    $result = $request->input('Body.stkCallback');
    $checkoutId = is_array($result) ? ($result['CheckoutRequestID'] ?? null) : null;
    $resultCode = is_array($result) ? ($result['ResultCode'] ?? null) : null;
    if (! is_string($checkoutId) || $checkoutId === '' || ! is_numeric($resultCode)) {
        Log::warning('M-Pesa result ignored: unexpected body');

        return $ack;
    }

    $payment = Payment::where('checkout_request_id', $checkoutId)->first();
    if (! $payment) {
        Log::warning('M-Pesa result for an unknown CheckoutRequestID', ['checkout_request_id' => $checkoutId]);

        return $ack;
    }

    if ((int) $resultCode === 0) {
        $items = collect($result['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $receipt = mb_substr((string) $items->get('MpesaReceiptNumber'), 0, 32) ?: null;

        if ($payment->status === 'paid') {
            return $ack;
        }

        if ($payment->status !== 'pending') {
            Payment::whereKey($payment->id)->whereIn('status', ['failed', 'cancelled'])->update(['status' => 'needs_review', 'reported_receipt' => $receipt, 'result_desc' => 'Success reported for a closed payment: check manually']);
            Log::error('M-Pesa payment needs review: success reported for a closed payment', ['payment' => $payment->id]);

            return $ack;
        }

        if ((int) $items->get('Amount') !== $payment->amount) {
            Payment::whereKey($payment->id)->where('status', 'pending')->update(['status' => 'needs_review', 'reported_receipt' => $receipt, 'result_desc' => 'Amount mismatch: check manually']);
            Log::error('M-Pesa payment needs review: amount mismatch', ['payment' => $payment->id]);

            return $ack;
        }

        Payment::whereKey($payment->id)->where('status', 'pending')->update(['reported_receipt' => $receipt, 'result_desc' => 'Success reported, confirming']);
    }

    if ($payment->status === 'pending') {
        ConfirmMpesaPayment::dispatch($payment->id);
    }

    return $ack;
});
