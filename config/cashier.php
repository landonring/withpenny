<?php

use App\Models\User;

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'path' => env('CASHIER_PATH', 'stripe'),
    'model' => User::class,
    'currency' => env('CASHIER_CURRENCY', 'usd'),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),
];
