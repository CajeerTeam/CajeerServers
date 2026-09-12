<?php
namespace Cajeer\Servers\Api\Controller;

final class SyncController
{
    public function run(): array
    {
        return ['queued' => true];
    }
}
