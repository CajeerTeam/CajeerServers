<?php
namespace Cajeer\Servers\Components;

final class InfrastructureTable
{
    public function render(array $assets): string
    {
        return '<table data-component="infrastructure-table"></table>';
    }
}
