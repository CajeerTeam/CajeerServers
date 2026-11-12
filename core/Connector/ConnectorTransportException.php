<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use RuntimeException;

/** Safe, finite error classification. Never contains remote headers, body or secrets. */
final class ConnectorTransportException extends RuntimeException
{
    private const MESSAGES = [
        'connector_auth_failed'=>'Remote API rejected the connector credential or its permissions.',
        'connector_rate_limited'=>'Remote API is rate limited. Try again later.',
        'connector_unavailable'=>'Remote API is temporarily unavailable.',
        'connector_protocol_error'=>'Remote API returned an unsupported HTTP or JSON response.',
        'connector_transport_failed'=>'Remote HTTPS connection or TLS verification failed.',
    ];
    public function __construct(public readonly string $category)
    {
        parent::__construct(self::MESSAGES[$category]??throw new \InvalidArgumentException('Unknown transport error category.'));
    }
    public static function forStatus(int $status):self
    {
        return new self(match(true){
            in_array($status,[401,403],true)=>'connector_auth_failed',
            $status===429=>'connector_rate_limited',
            $status>=500&&$status<=599=>'connector_unavailable',
            default=>'connector_protocol_error',
        });
    }
}
