<?php

declare(strict_types=1);

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ZeptoMailApiDriverServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('zeptomail', function ($app) {
            /** @var HttpFactory $http */
            $http = $app->make(HttpFactory::class);

            // Prefer config; fallback to env('ZEPTOMAIL_MAIL_KEY')
            $key = (string) (config('services.zeptomail.mail_key') ?? '');
            if (trim($key) === '') {
                $key = (string) env('ZEPTOMAIL_MAIL_KEY', '');
            }

            // Fail fast if still empty
            if (trim($key) === '') {
                throw new InvalidArgumentException(
                    "ZeptoMail driver misconfigured: mail key is empty. ".
                    "Set 'services.zeptomail.mail_key' in config/services.php or define ".
                    "ZEPTOMAIL_MAIL_KEY in your .env."
                );
            }

            return new ZeptoMailTransport(
                key: $key,
                http: $http,
                baseUrl: (string) config('services.zeptomail.endpoint', 'https://api.zeptomail.com'),
                timeout: (int) config('services.zeptomail.timeout', 30),
                retries: (int) config('services.zeptomail.retries', 2),
                retrySleepMs: (int) config('services.zeptomail.retry_sleep_ms', 200),
            );
        });
    }
}
