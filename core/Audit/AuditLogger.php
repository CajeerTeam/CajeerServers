<?php
namespace Cajeer\Servers\Audit;

/** Audit-grade logger for infrastructure actions. */
final class AuditLogger
{
    public function name(): string
    {
        return 'AuditLogger';
    }
}
