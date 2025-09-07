<?php

return [

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ML Inference Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the FastAPI ML inference service
    |
    */

    'ml_service' => [
        'url' => env('ML_SERVICE_URL', 'http://localhost:8001'),
        'timeout' => env('ML_SERVICE_TIMEOUT', 30),
        'retry_attempts' => env('ML_SERVICE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('ML_SERVICE_RETRY_DELAY', 1000), // milliseconds
        'api_key' => env('ML_SERVICE_API_KEY'),
        'enabled' => env('ML_SERVICE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Adjudicator Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for LLM-based fraud adjudication service
    |
    */

    'llm_adjudicator' => [
        'provider' => env('LLM_PROVIDER', 'openrouter'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'endpoint' => env('LLM_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
        'model' => env('LLM_MODEL', 'anthropic/claude-sonnet-4'),
        'timeout' => env('LLM_TIMEOUT', 30),
        'max_tokens' => env('LLM_MAX_TOKENS', 2000),
        'temperature' => env('LLM_TEMPERATURE', 0.1),
        'enabled' => env('LLM_ADJUDICATOR_ENABLED', true),
        'trigger_threshold_min' => env('LLM_TRIGGER_MIN', 0.3),
        'trigger_threshold_max' => env('LLM_TRIGGER_MAX', 0.7),
        'retry_attempts' => env('LLM_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('LLM_RETRY_DELAY', 1000), // milliseconds
    ],


];
