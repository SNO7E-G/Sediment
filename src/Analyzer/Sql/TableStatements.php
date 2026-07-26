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

    /**
     * @param bool $truncated the SQL was only partly resolved, so it may stop
     *        mid-name — see {@see names()}
     * @return list<string> names from every CREATE TABLE statement, in order
     */
    public static function created(string $sql, bool $truncated = false): array
    {
        return self::names($sql, 'CREATE', $truncated);
    }

    /**
     * @param bool $truncated the SQL was only partly resolved
     * @return list<string> names from every DROP TABLE statement, in order
     */
    public static function dropped(string $sql, bool $truncated = false): array
    {
        return self::names($sql, 'DROP', $truncated);
    }

    /**
     * When the SQL was only partly resolved, its tail was cut off at the first
     * unresolvable piece — and that cut may land inside the table name itself:
     * `"CREATE TABLE {$wpdb->prefix}logs{$suffix}"` resolves to
     * `CREATE TABLE {prefix}logs`, whose real name is not `{prefix}logs`. Reading
     * a name from it would invent a table the plugin never creates, and a
     * generated DROP would then target a table it does not own.
     *
     * So for truncated SQL the name must be followed by a real terminator —
     * whitespace, a bracket, a comma, or a semicolon — proving the name ended
     * before the cut.
     *
     * @return list<string>
     */
    private static function names(string $sql, string $verb, bool $truncated = false): array
    {
        $terminator = $truncated ? '(?=[\s`(;,])' : '(?=[\s`(;,]|$)';
        $pattern = '/^\s*' . $verb . '\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?' . self::NAME . $terminator . '/i';

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
