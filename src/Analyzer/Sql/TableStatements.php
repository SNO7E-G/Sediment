<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Sql;

/**
 * Pulls table names out of already-resolved SQL. Shared by the table detector
 * and the cleanup differ so CREATE and DROP are read with identical, anchored
 * rules.
 *
 * Matching is anchored to the start of each `;`-separated statement, so a table
 * name is only taken from a real CREATE/DROP TABLE statement — never from the
 * words "CREATE TABLE" appearing inside an INSERT value or a comment.
 */
final class TableStatements
{
    // Anchored at statement start; the name stops at whitespace, backtick,
    // paren, comma, semicolon, or a quote, so junk like `backup')` is excluded.
    private const NAME = '`?([^\s`(;,\'"]+)`?';

    /** @return list<string> names from every CREATE TABLE statement, in order */
    public static function created(string $sql): array
    {
        return self::names($sql, 'CREATE');
    }

    /** @return list<string> names from every DROP TABLE statement, in order */
    public static function dropped(string $sql): array
    {
        return self::names($sql, 'DROP');
    }

    /**
     * @return list<string>
     */
    private static function names(string $sql, string $verb): array
    {
        $pattern = '/^\s*' . $verb . '\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?' . self::NAME . '/i';

        $names = [];
        foreach (explode(';', $sql) as $statement) {
            if (preg_match($pattern, $statement, $matches) === 1) {
                $name = trim($matches[1], '`');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
