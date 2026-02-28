<?php

return [
    'currency' => env('CASHIER_CURRENCY', 'usd'),
    'subscription_name' => 'default',
    'plans' => [
        'starter' => [
            'label' => 'Starter',
            'monthly' => [
                'amount' => 0,
                'interval' => 'month',
                'stripe_price' => null,
            ],
            'yearly' => [
                'amount' => 0,
                'interval' => 'year',
                'stripe_price' => null,
            ],
        ],
        'pro' => [
            'label' => 'Pro',
            'monthly' => [
                'amount' => 15,
                'interval' => 'month',
                'stripe_price' => env('STRIPE_PRICE_PRO_MONTHLY'),
            ],
            'yearly' => [
                'amount' => 162,
                'interval' => 'year',
                'stripe_price' => env('STRIPE_PRICE_PRO_YEARLY'),
            ],
        ],
        'premium' => [
            'label' => 'Premium',
            'monthly' => [
                'amount' => 25,
                'interval' => 'month',
                'stripe_price' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
            ],
            'yearly' => [
                'amount' => 270,
                'interval' => 'year',
                'stripe_price' => env('STRIPE_PRICE_PREMIUM_YEARLY'),
            ],
        ],
    ],
];
