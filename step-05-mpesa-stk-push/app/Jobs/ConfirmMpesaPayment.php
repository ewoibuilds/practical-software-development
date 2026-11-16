<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Mpesa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ConfirmMpesaPayment implements ShouldQueue
{
    use Queueable;

    /** The only failure code Daraja documents: "Request cancelled by user" (Daraja, M-Pesa Express Query). */
    public const CANCELLED = 1032;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $paymentId) {}

    public function handle(Mpesa $mpesa): void
    {
        $payment = Payment::find($this->paymentId);
        if (! $payment || $payment->status !== 'pending' || ! $payment->checkout_request_id) {
            return;
        }

        $answer = $mpesa->query($payment->checkout_request_id)->throw();

        $code = $answer->json('ResultCode');
        if (! is_numeric($code)) {
            throw new RuntimeException("No result code yet for payment {$payment->id}; retrying.");
        }
        $code = (int) $code;
        $status = match ($code) {
            0 => 'paid',
            self::CANCELLED => 'cancelled',
            default => 'needs_review',
        };
        if ($status === 'needs_review') {
            Log::error('M-Pesa payment needs review: undocumented result code', ['payment' => $payment->id, 'result_code' => $code]);
        }

        Payment::whereKey($payment->id)->where('status', 'pending')->update([
            'status' => $status,
            'mpesa_receipt' => $status === 'paid' ? DB::raw('reported_receipt') : null,
            'result_code' => $code,
            'result_desc' => mb_substr((string) $answer->json('ResultDesc'), 0, 255),
        ]);
    }
}
