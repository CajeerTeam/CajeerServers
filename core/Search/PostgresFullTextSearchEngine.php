<?php
namespace Cajeer\Servers\Search;

/** PostgreSQL full-text search adapter. */
final class PostgresFullTextSearchEngine
{
    public function name(): string
    {
        return 'PostgresFullTextSearchEngine';
    }
}
