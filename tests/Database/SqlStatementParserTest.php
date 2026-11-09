<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Database;

use Cajeer\Servers\Database\SqlStatementParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlStatementParserTest extends TestCase
{
    public function testRealSchemaCanBeSplit(): void
    {
        $parser = new SqlStatementParser();
        $path = dirname(__DIR__, 2) . '/migrations/pgsql/0001_initial_schema.sql';
        $statements = $parser->split((string) file_get_contents($path));
        self::assertCount(12, $statements);
        self::assertStringStartsWith('CREATE TABLE', $statements[1]);
        self::assertStringContainsString('inventory_assets_search_idx', end($statements));
    }

    public function testPostgresFunctionWithQuotedSemicolons(): void
    {
        $source = <<<'SQL'
CREATE FUNCTION demo() RETURNS void AS $fn$
BEGIN
    RAISE NOTICE 'semi;colon';
END;
$fn$ LANGUAGE plpgsql;
SELECT 1;
SQL;
        self::assertCount(2, (new SqlStatementParser())->split($source));
    }

    public function testUnterminatedDollarQuoteRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new SqlStatementParser())->split('DO $tag$ SELECT 1;');
    }
}
