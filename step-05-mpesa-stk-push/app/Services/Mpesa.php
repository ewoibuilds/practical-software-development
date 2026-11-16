<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class Mpesa
{
    /** Push errors that Daraja documents as "request not processed", so no prompt reached the phone (Daraja, M-Pesa Express Simulate, Error Codes). */
    public const NO_PROMPT_ERRORS = ['500.001.1001', '500.003.02', '500.003.03'];

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
        return Cache::remember('mpesa.token', now()->addSeconds(3500), function () {
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
        $errorCode = $response->json('errorCode');

        return is_string($errorCode) && ($response->clientError() || in_array($errorCode, self::NO_PROMPT_ERRORS, true));
    }

    public function refusalMessage(Response $response): string
    {
        return match ($response->json('errorCode')) {
            '500.003.02', '500.003.03' => 'M-Pesa is busy right now. Nothing was charged; please try again in a few minutes.',
            '500.001.1001' => str_contains((string) $response->json('errorMessage'), 'lock subscriber')
                ? 'A payment prompt is already open on this phone. Nothing was charged; wait one minute, then try again.'
                : 'The payment could not be started. Nothing was charged.',
            default => 'The payment could not be started. Nothing was charged; you can try again.',
        };
    }

    public function stkPush(string $phone, int $amount, string $reference, string $description = 'Payment'): Response
    {
        if ($amount < 1 || $amount > 250000) {
            throw new InvalidArgumentException('Amount must be from 1 to 250,000 shillings.');
        }
        if ($reference === '' || strlen($reference) > 12 || $description === '' || strlen($description) > 13) {
            throw new InvalidArgumentException('AccountReference must be 1 to 12 characters and TransactionDesc 1 to 13.');
        }

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
            'AccountReference' => $reference,
            'TransactionDesc' => $description,
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
