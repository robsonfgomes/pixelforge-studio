<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generations
    |--------------------------------------------------------------------------
    |
    | Our business rules for image generations. The Pixelforge connection
    | itself lives in config/services.php. Only the throttles can be
    | changed per environment.
    |
    */

    // Users pay Pixelforge's cost plus this percentage, rounded up to the nanodollar.
    'markup_percent' => 25,

    // Requests per user per minute.
    'throttles' => [
        'requests_per_minute' => (int) env('GENERATION_REQUESTS_PER_MINUTE', 10),
        'estimates_per_minute' => (int) env('GENERATION_ESTIMATES_PER_MINUTE', 60),
    ],

    // How long a submission keeps retrying after "nothing was created" answers (429/503) before it fails at $0.
    'submit_retry_window_minutes' => 10,

    // How long a submission keeps retrying after an uncertain outcome (timeout, connection error) before giving up
    // without failing: Pixelforge may have created and billed the generation.
    'uncertain_outcome_retry_window_minutes' => 60,

    // Delay before each status poll, in seconds (45 s, then 1, 2, 5 and four times 10 minutes).
    'polling_delays_seconds' => [45, 60, 120, 300, 600, 600, 600, 600],

    // How long the page keeps polling a generation that is not final yet. It covers the uncertain-outcome window
    // (60 minutes) plus the backend polling (about 49 minutes); after it, the card says "Taking longer than expected"
    // and the page stops polling.
    'page_polling_window_minutes' => 120,

];
