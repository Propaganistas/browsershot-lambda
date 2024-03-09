<?php

namespace Propaganistas\BrowsershotLambda;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\Middleware;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;

class BrowsershotLambdaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'browsershot_lambda');

        class_alias(BrowsershotLambda::class, 'Wnx\SidecarBrowsershot\BrowsershotLambda');

        $this->app->singleton('lambda.pdf', function() {
            $config = $this->app['config']->get('browsershot_lambda', []);

            $client = new LambdaClient([
                'version' => 'latest',
                'region' => str($config['arn'])->after('arn:aws:lambda:')->before(':')->toString(),
                'credentials' => array_filter($config['credentials']),
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
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/config.php' => $this->app->configPath('browsershot_lambda.php'),
        ], 'config');

        if ($this->app['config']->get('browsershot_lambda.default')) {
            Pdf::default()->onLambda();
        }
    }
}
