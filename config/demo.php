<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo User
    |--------------------------------------------------------------------------
    |
    | The admin account DemoUserSeeder creates once in each environment, for
    | the evaluators to look around. The password is set only when the user
    | is created; running the seeder again changes nothing.
    |
    */

    'email' => 'demo@example.com',

    'password' => env('DEMO_USER_PASSWORD'),

];
