<?php

return [
    // Reddit app-only OAuth (create an app at https://www.reddit.com/prefs/apps)
    'reddit' => [
        'client_id'     => env('REDDIT_CLIENT_ID'),
        'client_secret' => env('REDDIT_CLIENT_SECRET'),
        // Reddit REQUIRES a descriptive, unique User-Agent or it will 429/403 you.
        'user_agent'    => env('REDDIT_USER_AGENT', 'enigma/0.1 by u/yourname'),
        // Free tier: 100 requests/min. We throttle jobs well under this.
        'max_rpm'       => (int) env('REDDIT_MAX_RPM', 90),
    ],

    // Python NLP microservice
    'nlp' => [
        'base_url' => env('NLP_BASE_URL', 'http://localhost:8000'),
        'timeout'  => (int) env('NLP_TIMEOUT', 120),
    ],
];
