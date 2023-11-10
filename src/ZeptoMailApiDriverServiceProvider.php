<?php

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class ZeptoMailApiDriverServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Mail::extend('zeptomail', function ($app) {
            return new ZeptoMailTransport(config('services.zeptomail.key', []));
        });
    }
}
