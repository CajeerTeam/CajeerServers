<?php
declare(strict_types=1);
namespace Cajeer\ServersSdk;
use Cajeer\Servers\Connector\{ConnectorContext,HttpsTransport};
use Cajeer\Servers\Credentials\CredentialProfile;
use RuntimeException;
/** Convenience base for trusted custom read-only bearer-token drivers. */
abstract class HttpConnector implements Connector
{
    public function __construct(private readonly HttpsTransport $transport,private readonly ?string $caFile=null) {}
    final protected function json(ConnectorContext $context,string $path,#[\SensitiveParameter] string $secret):\stdClass
    {
        $token=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        if (!is_string($token)||$token===''||preg_match('/[\x00-\x20\x7f]/',$token)) throw new RuntimeException('Invalid API token.');
        $header='Bearer '.$token;
        try {return HttpsTransport::jsonObject($this->transport->get($context->host,$context->port,$path,$header,$this->caFile));}
        finally {sodium_memzero($header);sodium_memzero($token);}
    }
}
