<?php
namespace Cajeer\Servers\Queue;

/** Queue driver contract. */
interface QueueDriverInterface
{
    public function name(): string;
}
