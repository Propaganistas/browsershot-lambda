<?php

namespace Propaganistas\BrowsershotLambda;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\Middleware;
use Exception;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Unit;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfFactory;

class BrowsershotLambdaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'browsershot_lambda');

        if (! class_exists('Wnx\SidecarBrowsershot\BrowsershotLambda')) {
            class_alias(BrowsershotLambda::class, 'Wnx\SidecarBrowsershot\BrowsershotLambda');
        }

        $this->app->singleton('lambda.pdf', function() {
            $config = $this->app['config']->get('browsershot_lambda', []);

            $region = str($config['arn'])->after('arn:aws:lambda:')->before(':')->toString();
            $credentials = $config['credentials'];

            throw_unless($region && $region !== $config['arn'], new Exception('AWS region could not be determined'));
            throw_unless($credentials['key'], 'AWS Access Key is empty');
            throw_unless($credentials['secret'], 'AWS Access Secret is empty');

            $client = new LambdaClient([
                'version' => 'latest',
                'region' => $region,
                'credentials' => $credentials,
            ]);

            // Add a middleware that will retry all requests provided the response
            // is a 409 Conflict. We have to do this because AWS puts a function
            // in a "Pending" state as they propagate the updates everywhere.
            $client->getHandlerList()->appendSign(
                Middleware::retry(function ($attempt, $command, $request, $result, $exception) {
                    /** @var $this LambdaClient */

                    // If the request succeeded, the exception will be null.
                    return $exception instanceof LambdaException
                        && $exception->getStatusCode() === 409
                        && Str::contains($exception->getAwsErrorMessage(), [
                            'The function is currently in the following state: Pending',
                            'is currently in the following state: \'Pending\'',
                            'An update is in progress for resource: '
                        ])
                        && $this->waitUntil('FunctionUpdated', ['FunctionName' => $command['FunctionName']]);
                })
            );

            return $client;
        });

        // @see https://github.com/spatie/laravel-pdf/issues/107#issuecomment-2760950993
        Pdf::resolved(function (PdfFactory $factory) {
            $instance = $factory->default()
                ->margins(0.4, 0.4, 0.4, 0.4, Unit::Inch)
                ->format(Format::A4);

            if ($this->app['config']->get('browsershot_lambda.default')) {
                $instance->onLambda();
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/config.php' => $this->app->configPath('browsershot_lambda.php'),
        ], 'config');
    }
}
