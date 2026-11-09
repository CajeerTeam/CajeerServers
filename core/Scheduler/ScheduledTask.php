<?php
declare(strict_types=1);

namespace Cajeer\Servers\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Five-field POSIX cron scheduler; correct timezone/DST transitions, DOM/DOW OR semantics. */
final class ScheduledTask
{
    private array $fields;
    private bool $domWildcard;
    private bool $dowWildcard;

    public function __construct(public readonly string $expression, public readonly string $timezone = 'UTC')
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) throw new InvalidArgumentException('Cron requires five fields.');
        new DateTimeZone($timezone);
        $this->domWildcard = $parts[2] === '*' || $parts[2] === '*/1';
        $this->dowWildcard = $parts[4] === '*' || $parts[4] === '*/1';
        $this->fields = [
            self::parse($parts[0], 0, 59), self::parse($parts[1], 0, 23),
            self::parse($parts[2], 1, 31), self::parse($parts[3], 1, 12),
            self::parse($parts[4], 0, 7, true),
        ];
    }

    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->timezone);
        // Absolute minute iteration ensures a skipped DST hour never invents local times.
        $minute = intdiv($after->getTimestamp(), 60) * 60 + 60;
        for ($n = 0; $n < 2 * 366 * 24 * 60; $n++, $minute += 60) {
            $local = (new DateTimeImmutable('@' . $minute))->setTimezone($zone);
            if ($this->matches($local)) return $local;
        }
        throw new RuntimeException('Cron expression has no occurrence within two years.');
    }

    public function matches(DateTimeImmutable $time): bool
    {
        $d = (int)$time->format('j');
        $w = (int)$time->format('w');
        $dom = isset($this->fields[2][$d]);
        $dow = isset($this->fields[4][$w]);
        $dayMatches = $this->domWildcard || $this->dowWildcard
            ? ($this->domWildcard ? $dow || $this->dowWildcard : $dom)
            : ($dom || $dow);
        return isset($this->fields[0][(int)$time->format('i')])
            && isset($this->fields[1][(int)$time->format('G')])
            && isset($this->fields[3][(int)$time->format('n')])
            && $dayMatches;
    }

    private static function parse(string $value, int $min, int $max, bool $weekday = false): array
    {
        if (!preg_match('/^[0-9*,\/\-]+$/D', $value) || strlen($value) > 120) throw new InvalidArgumentException('Invalid cron field.');
        $set=[];
        foreach (explode(',', $value) as $piece) {
            $tokens = explode('/', $piece);
            if (count($tokens)>2 || $tokens[0]==='') throw new InvalidArgumentException('Invalid cron step.');
            $step = isset($tokens[1]) && ctype_digit($tokens[1]) ? (int)$tokens[1] : 1;
            if ($step < 1 || $step > 100 || isset($tokens[1]) && $tokens[1]==='') throw new InvalidArgumentException('Cron step out of range.');
            if ($tokens[0]==='*') { $start=$min; $end=$max; }
            elseif (preg_match('/^(\d+)-(\d+)$/D',$tokens[0],$m)) { $start=(int)$m[1];$end=(int)$m[2]; }
            elseif (ctype_digit($tokens[0])) { $start=(int)$tokens[0];$end=isset($tokens[1]) ? $max : $start; }
            else throw new InvalidArgumentException('Invalid cron range.');
            if ($start<$min || $end>$max || $start>$end) throw new InvalidArgumentException('Cron value out of range.');
            for($n=$start;$n<=$end;$n+=$step) $set[$weekday && $n===7 ? 0 : $n]=true;
        }
        if ($set===[]) throw new InvalidArgumentException('Empty cron field.');
        return $set;
    }
}
