<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Only constructed before the HTTP POST is attempted. Never use for a POST failure. */
final class ConnectorActionNotSentException extends HttpException
{
    public function __construct(\Throwable $cause)
    {
        $code=$cause instanceof HttpException?$cause->errorCode:($cause instanceof ConnectorTransportException?$cause->category:'connector_failed');
        parent::__construct($cause instanceof HttpException?$cause->status:502,'Action was blocked before the remote POST.',$code);
    }
}
