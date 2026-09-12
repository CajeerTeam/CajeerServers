<?php
namespace Cajeer\ServersSdk;

final class ExtensionContext
{
    public function __construct(public readonly array $manifest) {}
}
