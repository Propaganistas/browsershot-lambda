<?php

namespace Propaganistas\BrowsershotLambda;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\ChromiumResult;

class BrowsershotLambda extends Browsershot
{
    public function base64pdf(): string
    {
        $command = $this->createPdfCommand();

        return $this->callBrowser($command);
    }

    protected function callBrowser(array $command): string
    {
        $output = $this->invokeLambda($command);

        if ($path = Arr::get($command, 'options.path')) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $output);
        }

        return $output;
    }

    protected function invokeLambda(array $command): string
    {
        if (str_starts_with($url = Arr::get($command, 'url'), 'file://')) {
            $command['_html'] = File::get($url);
        }

        // Clear additional output data fetched on last browser request
        $this->chromiumResult = null;

        $response = App::make('lambda.pdf')->invoke([
            'FunctionName' => Config::get('browsershot_lambda.arn'),
            'InvocationType' => 'RequestResponse',
            'LogType' => 'Tail',
            'Payload' => json_encode($command)
        ]);

        $payload = json_decode((string) $response->get('Payload'), true);
        $body = is_string($payload) ? json_decode($payload, true) : $payload;

        throw_if($response->get('FunctionError') !== '', $this->convertToException($body));

        $this->chromiumResult = new ChromiumResult($body);

        return rtrim($this->chromiumResult->getResult());
    }

    protected function convertToException($body): Exception
    {
        $message = Arr::get($body, 'errorMessage', 'Unknown error.');

        // Only the first two backtraces (plus the error) for the string.
        $trace = array_slice(Arr::get($body, 'trace', []), 0, 3);
        $trace = implode(' ', array_map('trim', $trace));

        return new Exception("Lambda Execution Exception: \"{$message} [TRACE] {$trace}\"");
    }
}