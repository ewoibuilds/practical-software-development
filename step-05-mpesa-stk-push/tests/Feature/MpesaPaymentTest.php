<?php

namespace Tests\Feature;

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Payment;
use App\Services\Mpesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class MpesaPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-callback-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://example.test',
            'services.mpesa' => [
                'env' => 'sandbox',
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'shortcode' => '174379',
                'passkey' => 'test-passkey',
                'callback_token' => $this->token,
            ],
        ]);

        Http::preventStrayRequests();
    }

    private function fakeDaraja(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing']),
            '*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']),
        ]);
    }

    private function startPayment(int $amount = 1): TestResponse
    {
        return $this->postJson('/api/payments', ['phone' => '0708374149', 'amount' => $amount]);
    }

    private function sendResult(int $resultCode = 0, int $amount = 1, ?string $checkoutId = 'ws_CO_1', ?string $token = null): TestResponse
    {
        $body = ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkoutId, 'ResultCode' => $resultCode, 'ResultDesc' => 'Result'];
        if ($resultCode === 0) {
            $body['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST1234AB'],
                ['Name' => 'TransactionDate', 'Value' => 20260101120000],
                ['Name' => 'PhoneNumber', 'Value' => 254708374149],
            ]];
        }

        return $this->postJson('/api/payments/result/'.($token ?? $this->token), ['Body' => ['stkCallback' => $body]]);
    }

    public function test_starting_a_payment_sends_the_stk_push_and_saves_the_ids(): void
    {
        $this->fakeDaraja();

        $this->startPayment()->assertStatus(202);

        $this->assertDatabaseHas('payments', ['phone' => '254708374149', 'amount' => 1, 'status' => 'pending', 'checkout_request_id' => 'ws_CO_1']);
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest')) {
                return false;
            }
            $timestamp = $request['Timestamp'];

            return str_starts_with($request->url(), 'https://sandbox.safaricom.co.ke')
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && preg_match('/^\d{14}$/', $timestamp) === 1
                && $request['Password'] === base64_encode('174379'.'test-passkey'.$timestamp)
                && $request['TransactionType'] === 'CustomerPayBillOnline'
                && $request['PartyA'] === '254708374149'
                && $request['PhoneNumber'] === '254708374149'
                && $request['PartyB'] === '174379'
                && $request['CallBackURL'] === 'https://example.test/api/payments/result/'.$this->token
                && strlen($request['AccountReference']) <= 12;
        });
    }

    public function test_bad_phone_numbers_and_amounts_are_rejected_before_any_call(): void
    {
        $this->fakeDaraja();

        $this->postJson('/api/payments', ['phone' => '12345', 'amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'amount']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_clear_refusal_fails_the_payment(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid Amount'], 400)]);

        $this->startPayment()->assertStatus(502);

        $this->assertSame('failed', Payment::sole()->status);
    }

    public function test_a_server_error_is_an_unknown_outcome_and_stays_pending(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response('upstream error', 503)]);

        $this->startPayment()->assertStatus(504)->assertJsonMissing(['message' => 'The payment could not be started. You can try again.']);

        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_no_answer_at_all_stays_pending(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::failedConnection()]);

        $this->startPayment()->assertStatus(504);

        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_the_ids_are_only_saved_on_a_pending_payment(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => function () {
            Payment::query()->update(['status' => 'needs_review']);

            return Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0']);
        }]);

        $this->startPayment();

        $this->assertNull(Payment::sole()->checkout_request_id);
    }

    public function test_the_placeholder_callback_token_is_refused(): void
    {
        $this->fakeDaraja();
        config(['services.mpesa.callback_token' => 'changeme']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        app(Mpesa::class)->callbackUrl();
    }

    public function test_the_placeholder_token_stops_a_payment_before_anything_is_sent(): void
    {
        $this->fakeDaraja();
        config(['services.mpesa.callback_token' => 'changeme']);

        $this->startPayment()->assertStatus(503);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'processrequest'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_access_token_failure_stops_a_payment_before_a_record_is_made(): void
    {
        $this->fakeDaraja(['*/oauth/v1/generate*' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'Server error'], 500)]);

        $this->startPayment()->assertStatus(503);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_requests_do_not_lock_a_phone_out(): void
    {
        $this->fakeDaraja();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/payments', ['phone' => '0708374149', 'amount' => 0])->assertUnprocessable();
        }

        $this->startPayment()->assertStatus(202);
    }

    public function test_a_phone_number_can_start_only_three_payments_in_ten_minutes(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => fn () => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_'.Str::random(10), 'ResponseCode' => '0'])]);

        $this->startPayment()->assertStatus(202);
        $this->startPayment()->assertStatus(202);
        $this->startPayment()->assertStatus(202);
        $this->startPayment()->assertStatus(429);

        $this->assertDatabaseCount('payments', 3);
    }

    public function test_a_wrong_callback_token_gets_404_and_changes_nothing(): void
    {
        $this->fakeDaraja();
        $this->startPayment();

        $this->sendResult(token: 'wrong-token')->assertNotFound();

        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_a_good_callback_is_confirmed_with_the_status_query_then_marked_paid(): void
    {
        $this->fakeDaraja();
        $this->startPayment();

        $this->sendResult()->assertOk()->assertJson(['ResultCode' => 0]);

        $payment = Payment::sole();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('TEST1234AB', $payment->mpesa_receipt);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/stkpushquery/v1/query') && $request['CheckoutRequestID'] === 'ws_CO_1');
    }

    public function test_the_same_callback_twice_is_processed_once(): void
    {
        $this->fakeDaraja();
        $this->startPayment();

        $this->sendResult()->assertOk();
        $this->sendResult()->assertOk();

        $this->assertSame('paid', Payment::sole()->status);
        Http::assertSentCount(3);
    }

    public function test_a_wrong_amount_goes_to_review(): void
    {
        $this->fakeDaraja();
        $this->startPayment(amount: 100);

        $this->sendResult(amount: 1)->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('TEST1234AB', Payment::sole()->reported_receipt);
        $this->assertNull(Payment::sole()->mpesa_receipt);
    }

    public function test_a_cancel_is_confirmed_with_the_status_query(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user'])]);
        $this->startPayment();

        $this->sendResult(resultCode: 1032)->assertOk();

        $this->assertSame('cancelled', Payment::sole()->status);
    }

    public function test_an_undocumented_result_code_goes_to_review_never_to_failed(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => '1037', 'ResultDesc' => 'No response from user'])]);
        $this->startPayment();

        $this->sendResult(resultCode: 1037)->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame(1037, Payment::sole()->result_code);
    }

    public function test_a_success_for_a_payment_in_review_keeps_the_reported_receipt(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => '4999', 'ResultDesc' => 'Still processing'])]);
        $this->startPayment();
        $this->sendResult(resultCode: 4999)->assertOk();
        $this->assertSame('needs_review', Payment::sole()->status);

        $this->sendResult()->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('TEST1234AB', Payment::sole()->reported_receipt);
    }

    public function test_an_open_session_on_the_phone_is_a_clear_refusal_with_wait_advice(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'Unable to lock subscriber, a transaction is already in process for the current subscriber'], 500)]);

        $this->startPayment()->assertStatus(502)->assertJsonFragment(['message' => 'A payment prompt is already open on this phone. Nothing was charged; wait one minute, then try again.']);

        $this->assertSame('failed', Payment::sole()->status);
    }

    public function test_a_busy_system_is_a_clear_refusal(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '500.003.02', 'errorMessage' => 'System is busy. Please try again in few minutes.'], 500)]);

        $this->startPayment()->assertStatus(502)->assertJsonFragment(['message' => 'M-Pesa is busy right now. Nothing was charged; please try again in a few minutes.']);

        $this->assertSame('failed', Payment::sole()->status);
    }

    public function test_an_internal_error_is_an_unknown_outcome(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '500.003.1001', 'errorMessage' => 'Internal Server Error'], 500)]);

        $this->startPayment()->assertStatus(504);

        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_account_reference_and_description_lengths_are_checked_before_sending(): void
    {
        $this->fakeDaraja();

        foreach ([['ORDER1234567X', 'Payment'], ['ORDER1', 'Payment for it'], ['', 'Payment']] as [$reference, $description]) {
            try {
                app(Mpesa::class)->stkPush('254708374149', 1, $reference, $description);
                $this->fail('A reference over 12 or a description over 13 characters must be refused.');
            } catch (\InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_access_token_is_reused_for_under_an_hour(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpush/v1/processrequest' => fn () => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_'.Str::random(10), 'ResponseCode' => '0'])]);

        $this->startPayment()->assertStatus(202);
        $this->travel(3400)->seconds();
        $this->startPayment()->assertStatus(202);
        $this->travel(200)->seconds();
        $this->startPayment()->assertStatus(202);

        Http::assertSentCount(5);
    }

    public function test_a_forged_cancel_cannot_close_a_payment_that_was_paid(): void
    {
        $this->fakeDaraja();
        $this->startPayment();

        $this->sendResult(resultCode: 1032)->assertOk();

        $this->assertSame('paid', Payment::sole()->status);
    }

    public function test_a_missing_result_code_keeps_the_payment_pending(): void
    {
        $this->fakeDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0'])]);
        $this->startPayment();

        $this->expectException(RuntimeException::class);
        try {
            (new ConfirmMpesaPayment(Payment::sole()->id))->handle(app(Mpesa::class));
        } finally {
            $this->assertSame('pending', Payment::sole()->status);
        }
    }

    public function test_a_success_for_a_failed_payment_goes_to_review(): void
    {
        $this->fakeDaraja();
        $this->startPayment();
        Payment::sole()->update(['status' => 'failed']);

        $this->sendResult()->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('TEST1234AB', Payment::sole()->reported_receipt);
    }

    public function test_a_result_without_a_checkout_request_id_is_ignored_but_acknowledged(): void
    {
        $this->fakeDaraja();
        $this->startPayment();
        $payment = Payment::sole();
        $payment->update(['checkout_request_id' => null]);

        $this->sendResult(checkoutId: null)->assertOk()->assertJson(['ResultCode' => 0]);
        $this->sendResult(checkoutId: '')->assertOk()->assertJson(['ResultCode' => 0]);
        $this->postJson('/api/payments/result/'.$this->token, ['unexpected' => 'shape'])->assertOk()->assertJson(['ResultCode' => 0]);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_result_for_an_unknown_payment_changes_nothing(): void
    {
        $this->fakeDaraja();
        $this->startPayment();

        $this->sendResult(checkoutId: 'ws_CO_unknown')->assertOk();

        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_an_expired_token_is_refreshed_once(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::sequence()->push(['access_token' => 'old'])->push(['access_token' => 'new']),
            '*/mpesa/stkpush/v1/processrequest' => Http::sequence()
                ->push(['requestId' => 'r-1', 'errorCode' => '404.001.03', 'errorMessage' => 'Invalid Access Token'], 404)
                ->push(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0']),
        ]);

        $this->startPayment()->assertStatus(202);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/processrequest') && $request->hasHeader('Authorization', 'Bearer new'));
        $this->assertDatabaseHas('payments', ['checkout_request_id' => 'ws_CO_1', 'status' => 'pending']);
    }
}
