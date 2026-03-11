<?php

return [
    'behavioral' => [
        'daily_limit' => 3,
        'skip_inactive_after_days' => 14,
        'active_recent_days' => 7,
        'min_meaningful_transactions' => 3,
        'meaningful_lookback_days' => 30,

        'windows' => [
            'morning' => ['start' => '07:00', 'end' => '10:00'],
            'noon' => ['start' => '11:00', 'end' => '14:00'],
            'afternoon' => ['start' => '16:00', 'end' => '19:00'],
        ],

        'thresholds' => [
            'week_over_week_percent' => 8,
            'high_single_expense_floor' => 150,
            'high_single_expense_multiplier' => 2.4,
            'drift_min_percent' => 8,
        ],

        'priorities' => [
            'weekly_checkin' => 95,
            'spending_insight' => 90,
            'drift_detection' => 85,
            'reflection_prompt' => 80,
            'tip' => 65,
        ],

        'tips' => [
            'Review your subscriptions this week.',
            'Try one five-minute spending check-in today.',
            'Pick one category to keep steady this week.',
            'A quick weekly review can reduce monthly surprises.',
            'One intentional adjustment can shift the whole month.',
        ],
    ],

    'system' => [
        'inactive_days' => 5,
        'inactive_cooldown_days' => 7,
        'update_cooldown_hours' => 24,

        'lifecycle_intervals' => [
            'new_user_24h' => 24,
            'new_user_3d' => 72,
            'new_user_7d' => 168,
        ],

        'priorities' => [
            'lifecycle' => 92,
            'inactivity_nudge' => 88,
            'update' => 98,
        ],
    ],
];
