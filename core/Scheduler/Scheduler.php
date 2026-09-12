<?php
namespace Cajeer\Servers\Scheduler;

/** Built-in cron/job runner scheduler. */
final class Scheduler
{
    public function name(): string
    {
        return 'Scheduler';
    }
}
