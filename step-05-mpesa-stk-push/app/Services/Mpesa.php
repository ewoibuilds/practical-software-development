<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Mpesa
{
    private function setting(string $name): string
    {
        $value = (string) config("services.mpesa.{$name}");
        if ($value === '') {
            throw new RuntimeException("The M-Pesa setting services.mpesa.{$name} is empty. Check your .env file.");
        }

        return $value;
    }

    public function callbackToken(): string
    {
        $token = $this->setting('callback_token');
        if (strlen($token) < 32 || $token === 'changeme') {
            throw new RuntimeException('MPESA_CALLBACK_TOKEN must be a random value of at least 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }

        return $token;
    }

    /** Checks the settings and gets an access token, so a payment is never started on a broken setup. */
    public function ready(): void
    {
        $this->callbackToken();
        $this->token();
    }

    private function base(): string
    {
        return config('services.mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function token(): string
    {
        return Cache::remember('mpesa.token', now()->addMinutes(50), function () {
            return Http::withBasicAuth($this->setting('consumer_key'), $this->setting('consumer_secret'))
                ->timeout(30)
                ->get($this->base().'/oauth/v1/generate', ['grant_type' => 'client_credentials'])
                ->throw()
                ->json('access_token');
        });
    }

    /** Sends the request; if Daraja says the token is no longer valid, gets a fresh one and tries once more. */
    private function post(string $path, array $body): Response
    {
        $send = fn () => Http::withToken($this->token())->acceptJson()->timeout(30)->post($this->base().$path, $body);
        $response = $send();
        if ($response->status() === 401 || $response->json('errorCode') === '404.001.03') {
            Cache::forget('mpesa.token');
            $response = $send();
        }

        return $response;
    }

    /** @return array{0: string, 1: string} the timestamp and the password Daraja expects */
    private function password(): array
    {
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $password = base64_encode($this->setting('shortcode').$this->setting('passkey').$timestamp);

        return [$timestamp, $password];
    }

    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/payments/result/'.$this->callbackToken();
    }

    /** Daraja accepted the request: the prompt is on its way. */
    public function accepted(Response $response): bool
    {
        return $response->successful()
            && $response->json('ResponseCode') === '0'
            && filled($response->json('CheckoutRequestID'));
    }

    /** Daraja clearly said no, so no prompt was sent. Anything else is an unknown outcome. */
    public function refused(Response $response): bool
    {
        if ($response->successful()) {
            return is_string($response->json('ResponseCode')) && $response->json('ResponseCode') !== '0';
        }

        return $response->clientError() && filled($response->json('errorCode'));
    }

    public function stkPush(string $phone, int $amount, string $reference): Response
    {
        [$timestamp, $password] = $this->password();

        return $this->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->setting('shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->setting('shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl(),
            'AccountReference' => substr($reference, 0, 12),
            'TransactionDesc' => 'Payment',
        ]);
    }

    public function query(string $checkoutRequestId): Response
    {
        [$timestamp, $password] = $this->password();

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->setting('shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }
}
