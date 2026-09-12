<?php
namespace Cajeer\Servers\Api\Controller;

final class WebhookController
{
    public function ingest(): array
    {
        return ['accepted' => true];
    }
}
