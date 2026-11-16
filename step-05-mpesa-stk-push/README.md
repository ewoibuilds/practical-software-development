# Step 05: Accept M-Pesa payments with STK Push

Tutorial: **Accept M-Pesa payments in Laravel** (on ewoibuilds.com: `/lessons/mpesa-stk-push-laravel`)

**Your workspace folder:** `~/ewoi-learning/laravel-app` (the same app, Laravel 13, with step 03 in place).

## What this step adds

`POST /api/payments` asks Safaricom's Daraja API (sandbox) to send an M-Pesa Express (STK Push) prompt. It is a **demo**: it takes the amount from the request and has no sign in, which a real shop must never do (the checkout project fixes both). It is rate limited: 10 requests a minute per address, and 3 prompts per phone every 10 minutes, counted only for valid requests. It checks the result token and gets an access token before saving anything (503 if either fails). Only a clear refusal documented by Daraja marks the payment failed: a `ResponseCode` other than "0", a 4xx with an `errorCode`, or `500.001.1001` (for example "Unable to lock subscriber": wait one minute), `500.003.02` (busy) or `500.003.03` (quota). Any other 500, an unreadable answer or no answer leaves it pending (504). Amounts must be 1 to 250,000, `AccountReference` at most 12 and `TransactionDesc` at most 13 characters, and the access token is reused for 3500 seconds (tokens expire after 3600). Sources: Daraja, M-Pesa Express Simulate and Authorization, developer.safaricom.co.ke.

`POST /api/payments/result/{token}` receives the result. It checks the secret token and the body shape, and always answers `{"ResultCode":0}`. Every result, success or failure, goes through `ConfirmMpesaPayment`, which asks Daraja's status query. Code 0 marks it paid and 1032 ("Request cancelled by user") cancelled, the only two codes Safaricom documents (Daraja, M-Pesa Express Query and Simulate, developer.safaricom.co.ke). Any other code goes to `needs_review`, and a missing code retries. A wrong amount, or a success for a closed payment, also goes to `needs_review`.

## The files

```
step-05-mpesa-stk-push/
├── .env.example                                       the MPESA_ names (placeholders only)
├── config/services.php                                the mpesa entry, read with config()
├── app/Services/Mpesa.php                             token, password, STK Push, status query
├── app/Providers/AppServiceProvider.php               the payments rate limit
├── app/Models/Payment.php
├── app/Jobs/ConfirmMpesaPayment.php
├── database/migrations/2026_01_02_000000_create_payments_table.php
├── routes/api.php                                     step 03's routes plus payments and the callback
└── tests/Feature/MpesaPaymentTest.php                 29 tests, all with Http::fake
```

Compare or copy these files into your app. The lessons migration and model from step 03 must already be there.

## Settings

Add the lines from `.env.example` here to your app's `.env.example` exactly as they are (`changeme`), and to your `.env` with your own sandbox values from the Daraja portal. Generate the callback token with (the app refuses `changeme` and anything shorter than 32 characters):

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Set `APP_URL` in `.env` to your public HTTPS tunnel address while testing with a real phone: the result address is built from it. Never commit `.env`, and never use production keys while learning.

## How to run it

From `~/ewoi-learning/laravel-app`, in three terminals:

```bash
php artisan migrate
php artisan serve
```

```bash
php artisan queue:work
```

and your tunnel. Then:

```bash
curl -s -X POST http://127.0.0.1:8000/api/payments -H "Accept: application/json" -H "Content-Type: application/json" -d "{\"phone\": \"0708374149\", \"amount\": 1}"
```

## Expected output

`202` with `{"payment":1,"message":"Check your phone and enter your M-Pesa PIN."}`. The row in `payments` starts as `pending` and becomes `paid` only after the confirmation job's status query says so.

## Check

You do not need Safaricom to check the logic. Copy `tests/Feature/MpesaPaymentTest.php` into your app and run:

```bash
php artisan test
```

Every test should pass. The tests use `Http::fake()` and `Http::preventStrayRequests()`, so no request leaves your computer.

## Commit

```bash
git status
git add .
git commit -m "Accept M-Pesa payments with STK Push and a safe callback"
```

Check that `.env` is not in the `git status` list before you commit.

## Next

Build the [mpesa-checkout-api](https://github.com/ewoibuilds/mpesa-checkout-api) project: orders, one payment per order, reconciliation and tests for every promise.

<!-- ewoi:step -->
---

**Next:** you finished the path: pick a project from [all free repos](https://github.com/ewoibuilds) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/practical-software-development/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/practical-software-development) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
