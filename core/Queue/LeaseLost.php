<?php
declare(strict_types=1);
namespace Cajeer\Servers\Queue;

/** A receipt no longer owns a job. Never retry or acknowledge it using that receipt. */
final class LeaseLost extends \RuntimeException {}
