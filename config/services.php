<?php

return [

    'openai' => [
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', env('VASCULAR_AGENT_MODEL', 'gpt-5-mini')),
        'supports_temperature' => env('OPENAI_SUPPORTS_TEMPERATURE', false),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'minimal'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'api' => [
        'key' => env('API_SECRET_KEY'),
    ],

    'azure_openai' => [
        'api_key' => env('AZURE_OPENAI_API_KEY'),
        'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat'),
        'api_version' => env('AZURE_OPENAI_VERSION', '2024-12-01-preview'),
    ],

];
