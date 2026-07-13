<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Service Account Credentials
    |--------------------------------------------------------------------------
    |
    | Path to your Firebase service-account JSON file, or the decoded array.
    | The file is generated in the Firebase Console under:
    |   Project Settings → Service Accounts → Generate New Private Key
    |
    | You can also pass the path via the FIREBASE_CREDENTIALS environment
    | variable, which is the recommended approach for production.
    |
    */
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

    /*
    |--------------------------------------------------------------------------
    | Dry-Run Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, FCM validates the message payload but does NOT deliver it
    | to devices. Useful for staging environments and integration testing.
    |
    */
    'dry_run' => (bool) env('PUSH_DRY_RUN', false),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Number of automatic retries on transient HTTP errors (429 / 5xx).
    | Set to 0 to disable retries.
    |
    | retry_delay_ms: base delay in milliseconds; doubled on each attempt
    |                 (exponential back-off).
    |
    */
    'retries' => (int) env('PUSH_RETRIES', 1),
    'retry_delay_ms' => (int) env('PUSH_RETRY_DELAY_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Polling Mode
    |--------------------------------------------------------------------------
    |
    | poll_store: Backend driver for the NotificationPoller.
    |   'cache'  — uses the default Laravel Cache driver (recommended).
    |   'array'  — in-memory only (for tests / local dev).
    |
    | poll_ttl: How long (in seconds) to keep queued notifications before
    |           they expire automatically. Only used by the 'cache' driver.
    |           Default: 86400 (24 hours).
    |
    */
    'poll_store' => env('PUSH_POLL_STORE', 'cache'),
    'poll_ttl' => (int) env('PUSH_POLL_TTL', 86400),

];
