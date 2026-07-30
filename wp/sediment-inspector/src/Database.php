<?php

declare(strict_types=1);

namespace Sediment\Inspector;

/**
 * The narrow slice of $wpdb the inspector uses.
 *
 * Depending on an interface rather than the global keeps every decision in
 * {@see LivePresence} testable without a WordPress install — which matters,
 * because the whole point of the inspector is to be trusted near live data.
 */
interface Database
{
    /** The table prefix for this site, e.g. `wp_`. */
    public function prefix(): string;

    /**
     * Run a prepared read and return the first column of the first row, or null.
     *
     * @param list<scalar> $params
     */
    public function value(string $sql, array $params = []): ?string;
}
