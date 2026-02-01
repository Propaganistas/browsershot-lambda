<?php

return [
    'arn' => env('PDF_LAMBDA_ARN'),

    'credentials' => [
        'key' => env('PDF_LAMBDA_ACCESS_KEY'),
        'secret' => env('PDF_LAMBDA_ACCESS_SECRET'),
    ],
];
