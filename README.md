# Laravel ZeptoMail API Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/brunocfalcao/laravel-zeptomail-driver.svg?style=flat-square)](https://packagist.org/packages/brunocfalcao/laravel-zeptomail-driver)
[![Total Downloads](https://img.shields.io/packagist/dt/brunocfalcao/laravel-zeptomail-driver.svg?style=flat-square)](https://packagist.org/packages/brunocfalcao/laravel-zeptomail-driver)

A lightweight **Symfony Mailer transport** for Laravel that delivers mail via **ZeptoMail’s HTTP API** (no SMTP).
It plugs into Laravel’s `mailers` and supports **CC/BCC/Reply-To**, **attachments & inline (CID) images**, **single & batch sending**, **template sending (single & batch)**, **custom MIME headers**, **open/click tracking flags**, and a **client reference**.
Under the hood it uses Laravel’s `Http` client, so you can **`Http::fake()`** in tests.

---

## Requirements

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x (Symfony Mailer)
- Outbound HTTPS and TLS 1.2 available
- A ZeptoMail account & API key (use the correct **region endpoint**, e.g. `.com` or `.eu`)

---

## Installation

Install via Composer:

```bash
composer require brunocfalcao/laravel-zeptomail-driver
```

If you don’t use package discovery, register the provider manually:

```php
// config/app.php
'providers' => [
    // ...
    Brunocfalcao\ZeptoMailApiDriver\ZeptoMailApiDriverServiceProvider::class,
],
```

---

## Configuration

### 1) Environment

```dotenv
# .env
MAIL_MAILER=zeptomail
MAIL_FROM_ADDRESS=hello@yourdomain.com
MAIL_FROM_NAME="Your App"

# Driver secret
ZEPTOMAIL_MAIL_KEY=your-zeptomail-api-key

# Region endpoint (pick the one for your account):
ZEPTO_MAIL_ENDPOINT=https://api.zeptomail.com
# ZEPTO_MAIL_ENDPOINT=https://api.zeptomail.eu

# Optional tuning
ZEPTO_MAIL_TIMEOUT=30
ZEPTO_MAIL_RETRIES=2
ZEPTO_MAIL_RETRY_MS=200

# Optional defaults
ZEPTO_MAIL_TEMPLATE_KEY=           # or ZEPTO_MAIL_TEMPLATE_ALIAS=
ZEPTO_MAIL_BOUNCE_ADDRESS=         # templates API only
ZEPTO_MAIL_TRACK_OPENS=false
ZEPTO_MAIL_TRACK_CLICKS=false
ZEPTO_MAIL_CLIENT_REFERENCE=
ZEPTO_MAIL_FORCE_BATCH=false
```

> The service provider reads `config('services.zeptomail.mail_key')` and falls back to `env('ZEPTOMAIL_MAIL_KEY')`.

### 2) `config/services.php`

```php
// config/services.php (excerpt)
return [
    // ...
    'zeptomail' => [
        // Primary: configure here. Fallback: env('ZEPTOMAIL_MAIL_KEY')
        'mail_key'        => env('ZEPTOMAIL_MAIL_KEY'),

        // Use .eu if your account lives in the EU region
        'endpoint'        => env('ZEPTO_MAIL_ENDPOINT', 'https://api.zeptomail.com'),

        // HTTP client tuning
        'timeout'         => env('ZEPTO_MAIL_TIMEOUT', 30),
        'retries'         => env('ZEPTO_MAIL_RETRIES', 2),
        'retry_sleep_ms'  => env('ZEPTO_MAIL_RETRY_MS', 200),

        // Optional defaults for convenience
        'template_key'    => env('ZEPTO_MAIL_TEMPLATE_KEY'),
        'template_alias'  => env('ZEPTO_MAIL_TEMPLATE_ALIAS'),
        'bounce_address'  => env('ZEPTO_MAIL_BOUNCE_ADDRESS'),
        'track_opens'     => env('ZEPTO_MAIL_TRACK_OPENS', false),
        'track_clicks'    => env('ZEPTO_MAIL_TRACK_CLICKS', false),
        'client_reference'=> env('ZEPTO_MAIL_CLIENT_REFERENCE'),
        'force_batch'     => env('ZEPTO_MAIL_FORCE_BATCH', false),
    ],
];
```

### 3) `config/mail.php`

```php
// config/mail.php (excerpt)
return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            // ...
        ],

        'zeptomail' => [
            'transport' => 'zeptomail', // registered by the ServiceProvider
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Example'),
    ],
];
```

---

## Usage

You can use this transport like any other Laravel mailer.

### Single email (default)

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceReady;

Mail::to('a@example.com')->send(new InvoiceReady());
// -> POST /v1.1/email
```

### Select the mailer per send

```php
Mail::mailer('zeptomail')->to('a@example.com')->send(new InvoiceReady());
```

### CC / BCC / Reply-To

```php
Mail::mailer('zeptomail')->send(
    (new App\Mail\SimpleMessage)
        ->to('to@example.com')
        ->cc(['cc1@example.com', 'cc2@example.com'])
        ->bcc('audit@example.com')
        ->replyTo('reply@example.com')
);
```

### Attachments & Inline (CID) images

```php
// App\Mail\ReportMail
public function build()
{
    $cid = $this->embed(public_path('img/logo.png')); // <img src="cid:{{ $cid }}">
    return $this->subject('Monthly Report')
        ->view('emails.report', compact('cid'))
        ->attach(storage_path('app/reports/monthly.pdf')); // regular attachment
}
```
The driver maps attachments to `attachments[]` and CID images to `inline_images[]` with `cid` set accordingly.

### Custom MIME headers, tracking, and client reference

```php
public function build()
{
    return $this->subject('Security Alert')
        ->view('emails.security')
        ->withSymfonyMessage(function (Symfony\Component\Mime\Email $email) {
            // Custom headers → go under Zepto's mime_headers
            $email->getHeaders()->addTextHeader('X-Custom-Header', 'abc-123');

            // Tracking flags
            $email->getHeaders()->addTextHeader('X-Zepto-Track-Opens', 'true');
            $email->getHeaders()->addTextHeader('X-Zepto-Track-Clicks', 'false');

            // Optional client reference
            $email->getHeaders()->addTextHeader('X-Zepto-Client-Reference', 'user#42-event#login');
        });
}
```

---

## Batch sending (per-recipient personalization & hidden recipients)

Use **batch** when sending to a collection of recipients. Recipients are **not visible to each other**. You can also provide **per-recipient `merge_info`**.

### Batch (non-template)

```php
use Illuminate\Support\Facades\Mail;

$recipients = ['alice@example.com', 'bob@example.com', 'carol@example.com'];

$perRecipient = [
    'alice@example.com' => ['name' => 'Alice', 'tier' => 'gold'],
    'bob@example.com'   => ['name' => 'Bob',   'tier' => 'silver'],
    // carol has no specific merge vars
];

Mail::to($recipients)->send(
    (new App\Mail\PromoMail)
        ->withSymfonyMessage(function (Symfony\Component\Mime\Email $email) use ($perRecipient) {
            // Force Zepto batch endpoint
            $email->getHeaders()->addTextHeader('X-Zepto-Batch', 'true');
            // Provide per-recipient merge vars
            $email->getHeaders()->addTextHeader('X-Zepto-PerRecipient-MergeInfo', json_encode($perRecipient));
        })
);
// -> POST /v1.1/email/batch
```

> You can globally enforce batch via `services.zeptomail.force_batch=true` if you prefer.

---

## Template sending

Send using a **template key or alias** (single or batch). Provide **global `merge_info`**, plus **per-recipient `merge_info`** in batch.

### Single template email

```php
use Illuminate\Support\Facades\Mail;

Mail::to('jane@example.com')->send(
    (new App\Mail\Templated)
        ->withSymfonyMessage(function (Symfony\Component\Mime\Email $email) {
            // Key or alias (the driver handles either)
            $email->getHeaders()->addTextHeader('X-Zepto-Template', 'my-template-alias'); // or 'ea36f19a...'

            // Global merge vars for this email
            $email->getHeaders()->addTextHeader('X-Zepto-MergeInfo', json_encode([
                'name'       => 'Jane',
                'reset_link' => 'https://example.com/reset/xyz',
            ]));

            // Optional bounce address (templates API)
            $email->getHeaders()->addTextHeader('X-Zepto-Bounce-Address', 'bounce@bounce.example.com');
        })
);
// -> POST /v1.1/email/template
```

### Batch template email

```php
use Illuminate\Support\Facades\Mail;

$recipients = ['a@example.com', 'b@example.com'];

$perRecipient = [
    'a@example.com' => ['name' => 'Alice', 'coupon' => 'ALC-10'],
    'b@example.com' => ['name' => 'Bob',   'coupon' => 'BOB-15'],
];

Mail::to($recipients)->send(
    (new App\Mail\Templated)
        ->withSymfonyMessage(function (Symfony\Component\Mime\Email $email) use ($perRecipient) {
            $email->getHeaders()->addTextHeader('X-Zepto-Template', 'my-template-alias');
            $email->getHeaders()->addTextHeader('X-Zepto-Batch', 'true');
            $email->getHeaders()->addTextHeader('X-Zepto-PerRecipient-MergeInfo', json_encode($perRecipient));

            // Optional global merge vars across all recipients
            $email->getHeaders()->addTextHeader('X-Zepto-MergeInfo', json_encode([
                'product' => 'Pro Plan',
            ]));
        })
);
// -> POST /v1.1/email/template/batch
```

---

## Testing with `Http::fake()`

Because this driver uses Laravel’s HTTP client, you can fake the API easily:

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceReady;

test('sends via zeptomail', function () {
    Http::fake([
        'https://api.zeptomail.com/*' => Http::response(['data' => [['message' => 'Email request received']]], 200),
        // or match your EU endpoint:
        // 'https://api.zeptomail.eu/*' => Http::response([...], 200),
    ]);

    Mail::to('test@example.com')->send(new InvoiceReady());

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/v1.1/email')
            && $request->method() === 'POST'
            && $request->json('subject') === 'Your invoice';
    });
});
```

---

## Queues

Mailables work seamlessly on queues. Ensure your workers have the same `.env` values and outbound HTTPS access. If using Horizon, watch memory/timeouts on bursts of batch sends.

---

## Troubleshooting

- **401 / auth errors** → Verify the key and that you’re using the correct regional endpoint (`.com` vs `.eu`).
- **“error” in response JSON** → The driver throws if the Zepto response includes an `error` object; check domain/sender verification and payload shape.
- **Inline images not showing** → Ensure you embed and reference the returned `cid` (`<img src="cid:{{ $cid }}">`).
- **Recipients visibility** → Use **batch** endpoints to hide recipients; single-email endpoint can expose them.
- **Rate limits / large sends** → Batch endpoints support a large number of recipients per request (subject to your Zepto plan). Split very large lists and back off with retries if needed.

---

## How it works

- `ZeptoMailApiDriverServiceProvider` registers the `zeptomail` transport.
- `ZeptoMailTransport` converts the Symfony `Email` into Zepto payloads and calls the correct endpoint based on headers/config:
  - `POST /v1.1/email` (single)
  - `POST /v1.1/email/batch` (batch)
  - `POST /v1.1/email/template` (template single)
  - `POST /v1.1/email/template/batch` (template batch)

It also maps: from/to/cc/bcc/reply-to, subject, html/text, attachments, inline images, `mime_headers`, tracking flags, `client_reference`, and (for templates) `merge_info` & optional `bounce_address`.

---

## Changelog

See [CHANGELOG](CHANGELOG.md).

---

## Contributing

PRs are welcome. If you add fields, please link the specific Zepto docs section in your PR description and include tests with `Http::fake()`.

---

## Security

Keep secrets in `.env`. Rotate API keys periodically. Consider setting a dedicated **bounce address** when using template APIs.

---

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for details.
