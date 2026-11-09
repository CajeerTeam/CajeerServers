<?php
declare(strict_types=1);

namespace Cajeer\Servers\Database;

use RuntimeException;

/** Splits PostgreSQL SQL scripts safely across strings, nested comments and dollar quotes. */
final class SqlStatementParser
{
    /** @return list<string> */
    public function split(string $source): array
    {
        if (str_contains($source, "\0")) {
            throw new RuntimeException('Migration SQL contains a NUL byte.');
        }
        $length = strlen($source);
        $statements = [];
        $current = '';
        $state = 'normal';
        $tag = '';
        $commentDepth = 0;
        $hasCode = false;
        $escapeSingle = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($state === 'line_comment') {
                if ($char === "\n") {
                    $current .= "\n";
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                if ($char === '/' && $next === '*') {
                    $commentDepth++;
                    $i++;
                } elseif ($char === '*' && $next === '/') {
                    $commentDepth--;
                    $i++;
                    if ($commentDepth === 0) {
                        $current .= ' ';
                        $state = 'normal';
                    }
                }
                continue;
            }
            if ($state === 'single') {
                $current .= $char;
                if ($char === "'" && $next === "'") {
                    $current .= $next;
                    $i++;
                } elseif ($char === "\\" && $escapeSingle && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($char === "'") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'double') {
                $current .= $char;
                if ($char === '"' && $next === '"') {
                    $current .= $next;
                    $i++;
                } elseif ($char === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'dollar') {
                if (substr_compare($source, $tag, $i, strlen($tag)) === 0) {
                    $current .= $tag;
                    $i += strlen($tag) - 1;
                    $state = 'normal';
                } else {
                    $current .= $char;
                }
                continue;
            }
            // Normal SQL context.
            if ($char === '-' && $next === '-') {
                $state = 'line_comment';
                $current .= ' ';
                $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $state = 'block_comment';
                $commentDepth = 1;
                $current .= ' ';
                $i++;
                continue;
            }
            if ($char === "'") {
                $escapeSingle = (bool) preg_match('/(?<![A-Za-z0-9_])E$/i', $current);
                $state = 'single';
            } elseif ($char === '"') {
                $state = 'double';
            } elseif ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/A', $source, $matches, 0, $i)) {
                $tag = $matches[0];
                $state = 'dollar';
                $current .= $tag;
                $i += strlen($tag) - 1;
                $hasCode = true;
                continue;
            } elseif ($char === ';') {
                if ($hasCode) {
                    $statements[] = trim($current);
                }
                $current = '';
                $hasCode = false;
                continue;
            }
            if (!ctype_space($char)) {
                $hasCode = true;
            }
            $current .= $char;
        }
        if (!in_array($state, ['normal', 'line_comment'], true)) {
            throw new RuntimeException('Migration SQL contains an unterminated string, identifier or comment.');
        }
        if ($hasCode) {
            $statements[] = trim($current);
        }
        return $statements;
    }

}
