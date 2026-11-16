<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Mpesa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfirmMpesaPayment implements ShouldQueue
{
    use Queueable;

    /** The only result codes treated as a final "not paid". Any other code is retried, never guessed. */
    public const FINAL_FAILURES = [
        1 => 'failed',
        1032 => 'cancelled',
        1019 => 'failed',
        1025 => 'failed',
        1037 => 'failed',
        2001 => 'failed',
        9999 => 'failed',
    ];

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
        $status = $code === 0 ? 'paid' : (self::FINAL_FAILURES[$code] ?? null);
        if ($status === null) {
            throw new RuntimeException("Result code {$code} for payment {$payment->id} is not a known final answer; retrying.");
        }

        Payment::whereKey($payment->id)->where('status', 'pending')->update([
            'status' => $status,
            'mpesa_receipt' => $status === 'paid' ? DB::raw('reported_receipt') : null,
            'result_code' => $code,
            'result_desc' => mb_substr((string) $answer->json('ResultDesc'), 0, 255),
        ]);
    }
}
