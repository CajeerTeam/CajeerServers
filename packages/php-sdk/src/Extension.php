<?php
namespace Cajeer\ServersSdk;

interface Extension
{
    public function register(ExtensionContext $context): void;
}
