<?php
namespace Cajeer\ServersSdk;

interface Connector
{
    public function health(): array;
    public function sync(array $options = []): array;
}
