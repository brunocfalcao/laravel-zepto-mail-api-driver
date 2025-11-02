<?php

declare(strict_types=1);

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class ZeptoMailApiDriverServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The closure receives the *mailer config array* (not the container).
        Mail::extend('zeptomail', function (array $config = []) {
            /** @var HttpFactory $http */
            $http = app(HttpFactory::class);

            // Resolve the key with precedence:
            // 1) per-mailer config (config('mail.mailers.zeptomail.mail_key'))
            // 2) services.zeptomail.mail_key
            // 3) env('ZEPTOMAIL_MAIL_KEY')
            $key = (string) ($config['mail_key']
                ?? config('services.zeptomail.mail_key')
                ?? env('ZEPTOMAIL_MAIL_KEY', ''));

            if (mb_trim($key) === '') {
                throw new InvalidArgumentException(
                    'ZeptoMail driver misconfigured: mail key is empty. '.
                    "Set 'mail.mailers.zeptomail.mail_key' or 'services.zeptomail.mail_key', ".
                    'or define ZEPTOMAIL_MAIL_KEY in your .env.'
                );
            }

            // Allow per-mailer overrides; fallback to services.* defaults
            $endpoint = (string) ($config['endpoint'] ?? config('services.zeptomail.endpoint', 'https://api.zeptomail.com'));
            $timeout = (int) ($config['timeout'] ?? config('services.zeptomail.timeout', 30));
            $retries = (int) ($config['retries'] ?? config('services.zeptomail.retries', 2));
            $retrySleepMs = (int) ($config['retry_sleep_ms'] ?? config('services.zeptomail.retry_sleep_ms', 200));

            return new ZeptoMailTransport(
                key: $key,
                http: $http,
                baseUrl: $endpoint,
                timeout: $timeout,
                retries: $retries,
                retrySleepMs: $retrySleepMs,
            );
        });
    }
}
