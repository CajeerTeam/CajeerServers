<?php
declare(strict_types=1);

return [
    'enabled' => env('SCHEDULER_ENABLED', true) === true,
    'runner' => 'postgresql-outbox',
    'batch_size' => (int) env('SCHEDULER_BATCH', 25),
];
