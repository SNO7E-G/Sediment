<?php

declare(strict_types=1);

namespace Sediment\Source;

/**
 * The tiny slice of HTTP the wordpress.org client needs.
 *
 * An interface rather than a curl call inlined into the client, so the corpus
 * tests can run without touching the network — a test suite that depends on
 * someone else's server is a test suite that fails for reasons that are not
 * about this code.
 */
interface Http
{
    /**
     * Fetch a URL, returning its body.
     *
     * @throws \RuntimeException when the request fails or the response is not 200.
     */
    public function get(string $url): string;
}
