<?php

namespace Brunocfalcao\ZeptoMailApiDriver;

use Brunocfalcao\ZeptoMailApiDriver\ZeptoMailTransport;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class ZeptoMailApiDriverServiceProvider extends MailServiceProvider
{
    protected function registerSwiftTransport()
    {
        parent::registerSwiftTransport();

        Mail::extend('zeptomail', function ($app) {
            $config = $app['config']->get('services.zeptomail', []);
            return new ZeptoMailTransport($config['key']);
        });
    }
}
