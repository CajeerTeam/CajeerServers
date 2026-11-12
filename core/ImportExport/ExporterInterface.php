<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;

/** Trusted exporter receives only authorized, bounded, normalized rows. */
interface ExporterInterface
{
    public function name():string;
    public function supports(string $kind):bool;
    /** @param iterable<array<string,mixed>> $records */
    public function encode(string $kind,iterable $records):string;
}
