<?php

return [
    'debug' => (bool) env('STATEMENT_PARSER_DEBUG', false),
    'beta_users' => array_values(array_filter(array_map(
        static fn (string $value) => trim(strtolower($value)),
        explode(',', (string) env('STATEMENT_BETA_USERS', 'landonringeisen'))
    ))),
];
