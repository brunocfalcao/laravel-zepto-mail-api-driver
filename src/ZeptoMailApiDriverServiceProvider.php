<?php

declare(strict_types=1);

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ZeptoMailApiDriverServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('zeptomail', function ($app) {
            // Prefer config, fallback to env('ZEPTOMAIL_MAIL_KEY')
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

            // Instantiate the transport with a valid key
            return new ZeptoMailTransport($key);
        });
    }
}
