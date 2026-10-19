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

    /** Core tables whose rows a plugin's own keys live in: table => [key column, artifact type, network-wide]. */
    private const KEYED_ROWS = [
        '{prefix}options' => ['option_name', 'option', false],
        '{prefix}sitemeta' => ['meta_key', 'option', true],
        '{prefix}postmeta' => ['meta_key', 'post_meta', false],
        '{prefix}usermeta' => ['meta_key', 'user_meta', false],
        '{prefix}termmeta' => ['meta_key', 'term_meta', false],
        '{prefix}commentmeta' => ['meta_key', 'comment_meta', false],
    ];

    /**
     * Rows a bulk `DELETE FROM` removes from a core keyed table — the usual
     * teardown, `DELETE FROM {$wpdb->options} WHERE option_name LIKE 'acme\_%'`.
     *
     * Only a WHERE made purely of `column LIKE/= '...'` terms joined by OR is
     * read. Anything else — an AND, a second column, a NOT — can narrow the
     * delete, and crediting a narrowed delete would claim rows it keeps.
     *
     * @return list<array{0: string, 1: string, 2: bool}> [artifact type, anchored regex matching the keys removed, whether the table is network-wide]
     */
    public static function deletedRows(string $sql): array
    {
        $rows = [];
        foreach (explode(';', $sql) as $statement) {
            if (preg_match('/^\s*DELETE\s+FROM\s+`?(\{prefix\}\w+)`?\s+WHERE\s+(.+)$/is', $statement, $m) !== 1) {
                continue;
            }

            [$column, $type, $network] = self::KEYED_ROWS[strtolower($m[1])] ?? [null, null, false];
            if ($column === null || $type === null) {
                continue;
            }

            $term = '`?' . $column . '`?\s+(LIKE|=)\s+\'((?:[^\'\\\\]|\\\\.)*)\'';
            if (preg_match('/^\s*\(?\s*' . $term . '(?:\s+OR\s+' . $term . ')*\s*\)?\s*$/is', $m[2]) !== 1) {
                continue;
            }

            preg_match_all('/' . $term . '/is', $m[2], $terms, PREG_SET_ORDER);
            foreach ($terms as $t) {
                $rows[] = [$type, self::likeRegex($t[2], strtoupper($t[1]) === '='), $network];
            }
        }

        return $rows;
    }

    /**
     * A LIKE pattern as an anchored regex: `%` any run, `_` any one character,
     * a backslash escapes the next. Case-insensitive, like MySQL's default
     * collation. An `=` value is read literally.
     */
    private static function likeRegex(string $pattern, bool $exact): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif (!$exact && $char === '%') {
                $regex .= '.*';
            } elseif (!$exact && $char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return '/^' . $regex . '$/is';
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

        // Anchored at statement start; a name stops at whitespace, backtick,
        // paren, comma, semicolon, or a quote, so junk like `backup')` is excluded.
        // DROP TABLE takes a list (`DROP TABLE IF EXISTS a, b`); CREATE takes one.
        // The terminator applies to the end of the list, so a truncated last
        // name is dropped by backtracking while the names before it stand.
        $name = '`?[^\s`(;,\'"]+`?';
        $list = $verb === 'DROP' ? $name . '(?:\s*,\s*' . $name . ')*' : $name;
        $pattern = '/^\s*' . $verb . '\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(' . $list . ')' . $terminator . '/i';

        $names = [];
        foreach (explode(';', $sql) as $statement) {
            if (preg_match($pattern, $statement, $matches) !== 1) {
                continue;
            }

            foreach (explode(',', $matches[1]) as $part) {
                $part = trim($part, " \t\r\n`");
                if ($part !== '') {
                    $names[] = $part;
                }
            }
        }

        return $names;
    }
}
