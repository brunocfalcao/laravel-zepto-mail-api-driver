# Laravel ZeptoMail API Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/brunocfalcao/laravel-zeptomail-driver.svg?style=flat-square)](https://packagist.org/packages/brunocfalcao/laravel-zeptomail-driver)
[![Total Downloads](https://img.shields.io/packagist/dt/brunocfalcao/laravel-zeptomail-driver.svg?style=flat-square)](https://packagist.org/packages/brunocfalcao/laravel-zeptomail-driver)

A lightweight **Symfony Mailer transport** for Laravel that delivers mail via **ZeptoMail’s HTTP API** (no SMTP).
It plugs into Laravel’s `mailers` just like any first-party transport.

---

## Requirements

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x (uses Symfony Mailer)
- `ext-curl` enabled (the driver uses cURL under the hood)
- A ZeptoMail account & API key

---

## Installation

Install via Composer:

```bash
composer require brunocfalcao/laravel-zeptomail-driver
```

> **Not on Packagist yet?**
> Add a [VCS or path repository](https://getcomposer.org/doc/05-repositories.md) to your `composer.json` and then `composer require brunocfalcao/laravel-zeptomail-driver:*`.

The service provider registers the custom transport with Laravel automatically. If you don’t use package discovery, register it manually:

```php
// config/app.php
'providers' => [
    // ...
    Brunocfalcao\ZeptoMailApiDriver\ZeptoMailApiDriverServiceProvider::class,
],
```

---

## Configuration

### 1) Add your ZeptoMail credentials

```dotenv
# .env
MAIL_MAILER=zeptomail
MAIL_FROM_ADDRESS=hello@yourdomain.com
MAIL_FROM_NAME="Your App"

ZEPTO_MAIL_KEY=your-zeptomail-api-key
```

### 2) Add the service config

```php
// config/services.php
return [
    // ...
    'zeptomail' => [
        'key' => env('ZEPTO_MAIL_KEY'),
    ],
];
```

### 3) Register the mailer

```php
// config/mail.php
return [
    // ...
    'mailers' => [
        // keep your existing mailers...
        'smtp' => [/* ... */],

        // add ZeptoMail
        'zeptomail' => [
            'transport' => 'zeptomail', // <-- registered by the service provider
        ],
    ],

    // set a default if you want all mail to go via ZeptoMail
    'default' => env('MAIL_MAILER', 'smtp'),
];
```

> You can either set `MAIL_MAILER=zeptomail` globally, or select the mailer **per send** (see below).

---

## Usage

You can use the driver exactly like any other Laravel mailer.

### Send a quick message

```php
use Illuminate\Support\Facades\Mail;

Mail::mailer('zeptomail')->raw('Hello from ZeptoMail', function ($message) {
    $message->to('john@example.com')
        ->subject('Test via ZeptoMail');
});
```

If `MAIL_MAILER=zeptomail` is your default, a regular `Mail::raw()` works as usual.

### Send a Mailable

```php
// App\Mail\WelcomeMail extends Illuminate\Mail\Mailable

use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

Mail::mailer('zeptomail')
    ->to('jane@example.com')
    ->send(new WelcomeMail());
```

Or set the mailer inside the Mailable:

```php
public function __construct()
{
    $this->mailer('zeptomail');
}
```

### Attachments

Attachments are supported. Use standard Mailable APIs:

```php
public function build()
{
    return $this->subject('Monthly Report')
        ->view('emails.report')
        ->attach(storage_path('app/reports/monthly.pdf'));
}
```

### Per-notification mailer

```php
use Illuminate\Notifications\Messages\MailMessage;

public function toMail($notifiable)
{
    return (new MailMessage)
        ->mailer('zeptomail')
        ->subject('Security Alert')
        ->line('A sign-in occurred on your account.');
}
```

---

## What this driver sends

The current payload includes:

- **From** (address & name)
- **To** (one or more recipients)
- **Subject**
- **HTML body** & **Text body**
- **Attachments** (as base64)

> The transport converts your Laravel/Symfony `Email` into ZeptoMail’s JSON and posts it to the ZeptoMail API.

---

## Limitations (current scope)

This first version intentionally focuses on a clean, minimal payload:

- CC/BCC, Reply-To, custom headers are not mapped yet.
- Inline/embedded attachments (CID images) are not mapped yet.
- ZeptoMail template IDs, tags, or advanced analytics are not mapped.

If you need any of the above, open an issue or PR and we can extend the payload mapper.

---

## Testing locally

Use Laravel’s `Mail::fake()` for unit tests:

```php
use Illuminate\Support\Facades\Mail;

Mail::fake();
// exercise your code that sends mail...
Mail::assertSent(App\Mail\WelcomeMail::class);
```

For manual verification in a dev environment, set `MAIL_MAILER=zeptomail` and send to a test inbox.
Remember to use a **verified sender/domain** in ZeptoMail.

---

## Troubleshooting

- **`cURL Error`**
  Ensure `ext-curl` is enabled and outbound HTTPS is allowed. The driver enforces TLS 1.2.

- **“Error sending email” (JSON response)**
  The exception message includes the ZeptoMail error body—check credentials, sender/domain verification, and payload formatting.

- **Queue workers**
  If you queue mail, make sure your workers have `ext-curl` and the same env/config values as your web process.

---

## How it works (under the hood)

- `ZeptoMailApiDriverServiceProvider` registers a new mail transport named **`zeptomail`**.
- `ZeptoMailTransport` extends Symfony’s `AbstractTransport`, converts the `SentMessage` to a `SymfonyEmail`, builds the ZeptoMail JSON payload, and sends it via cURL to the ZeptoMail API endpoint.

This keeps everything idiomatic to Laravel’s `Mail` facade and Symfony Mailer, so you can switch between mailers freely.

---

## Roadmap

- CC/BCC/Reply-To mapping
- Inline attachments (CID) support
- ZeptoMail templates/tags/headers
- Swap cURL to Laravel’s `Http` client with fakes for easier testing

---

## Security

Never commit secrets. Keep your ZeptoMail API key in `.env` and rotate it periodically.

---

## Changelog

See [CHANGELOG](CHANGELOG.md).

---

## Contributing

PRs are welcome! Please include tests where practical and describe any payload changes clearly.

---

## Credits

- [Bruno C. Falcão](https://github.com/brunocfalcao)
- Inspired by the Laravel & Symfony Mailer ecosystems

---

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for details.
