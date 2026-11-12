<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Scheduler\ScheduledTask;
use DateTimeImmutable;
use DateTimeZone;

/** Fixed interval or POSIX cron; missed ticks coalesce instead of replaying a backlog. */
final readonly class CollectionSchedule
{
    private ?ScheduledTask $task;
    public function __construct(public ?int $interval, public ?string $cron, public string $timezone='UTC')
    {
        if (($interval===null)===($cron===null) || ($interval!==null && ($interval<60 || $interval>86400)) || strlen($timezone)>64 || !in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true) || ($cron!==null && strlen($cron)>120))
            throw new HttpException(422,'Invalid collection schedule.','collection_invalid');
        try { $this->task=$cron===null?null:new ScheduledTask($cron,$timezone); }
        catch (\InvalidArgumentException $e) {throw new HttpException(422,'Invalid collection cron.','collection_invalid');}
    }
    public function next(DateTimeImmutable $now): string
    {
        try {$next=$this->task?->next($now)??$now->modify('+'.$this->interval.' seconds');}
        catch (\RuntimeException $e) {throw new HttpException(422,'Collection schedule has no next occurrence.','collection_invalid');}
        return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
