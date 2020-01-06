<?php

namespace Netsells\Logger;

use Illuminate\Support\ServiceProvider;
use Ramsey\Uuid\Uuid;

class LaravelServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app['request_id'] = Uuid::uuid4();
    }
}
