# Netsells Logger - PHP

A log formatter for use with PHP. 

At the moment, this only comes with Laravel support. It also adds a request_id to the container to allow for request tagging.


## Installation and Setup

```bash
composer require netsells/logger-php
```

Add the following channel to your `config/logging.php` file. Ensure that you set the project value. Note that you can also set the component and subComponent for more distributed web apps.

```php
'daily_json' => [
    'driver' => 'daily',
    'path' => env('LOG_PATH', storage_path('logs/laravel-json.log')),
    'formatter' => Netsells\Logger\LaravelLogger::class,
    'formatter_with' => [
        'project' => 'project-name',
        // optional - 'component' => 'core',
        // optional - 'subComponent' => 'php',
        // optional - 'environment' => env('APP_ENV'),
    ],
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```

When you are ready to use the new format, either set your `LOG_CHANNEL` to `daily_json` or add `daily_json` to your stack channels to benefit from multiple channels.

## TODO

* Add tests
* Add setup examples for monolog
