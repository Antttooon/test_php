<?php

declare(strict_types=1);

return [
    'error.db_connection' => 'Database connection failed',
    'error.invalid_date' => 'Invalid or missing `date` (expected YYYY-MM-DD).',
    'error.invalid_id' => 'Invalid or missing `id` (max 10 chars).',
    'error.invalid_from_to' => 'Invalid or missing `from`/`to` (expected YYYY-MM-DD).',
    'error.from_gt_to' => '`from` must be less than or equal to `to`.',
    'error.unknown_route' => 'Unknown API route.',
    'error.server' => 'Internal server error',
];
