<?php

declare(strict_types=1);

namespace Sediment\Source;

use RuntimeException;

/**
 * Fetches over HTTPS with curl where it is available, falling back to PHP's
 * stream wrappers where it is not.
 *
 * Neither extension is required by composer.json: only `fetch` needs the
 * network, and forcing an extension on everyone who just wants to scan a
 * directory would be rude. Whichever route is taken, TLS verification stays on,
 * and nothing is fetched or followed over anything but HTTPS.
 */
final class CurlHttp implements Http
{
    /**
     * @param non-empty-string $userAgent
     */
    public function __construct(
        private readonly string $userAgent = 'Sediment (+https://github.com/SNO7E-G/Sediment)',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function get(string $url): string
    {
        if (!self::isHttps($url)) {
            throw new RuntimeException("Refusing to fetch {$url}: only https:// URLs are allowed.");
        }

        return function_exists('curl_init') ? $this->viaCurl($url) : $this->viaStream($url);
    }

    private static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function viaCurl(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException("Could not open a request for {$url}");
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A redirect may not downgrade the request to plain HTTP (or
            // anything else curl speaks).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException("Request to {$url} failed: {$error}" . self::certificateHint($error, 'curl.cainfo'));
        }

        if ($status !== 200) {
            throw new RuntimeException("Request to {$url} returned HTTP {$status}");
        }

        return (string) $body;
    }

    private function viaStream(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'user_agent' => $this->userAgent,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        error_clear_last();
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            // The @ keeps PHP's warning off the terminal, not out of the
            // message: a TLS or DNS failure is the one thing worth reading.
            $error = error_get_last()['message'] ?? 'no further detail';
            throw new RuntimeException(sprintf(
                'Request to %s failed (no curl extension; the stream wrapper said: %s)%s',
                $url,
                $error,
                self::certificateHint($error, 'openssl.cafile'),
            ));
        }

        // The stream wrapper follows redirects on its own, so check every
        // hop it took: bytes that crossed plain HTTP are not used. PHP 8.4
        // added a function for the headers; before it, only the magic local
        // exists, which 8.5 deprecates by name, so it is not named here.
        $location = self::plainHttpRedirect(
            function_exists('http_get_last_response_headers')
                ? http_get_last_response_headers()
                : get_defined_vars()['http_response_header'],
        );
        if ($location !== null) {
            throw new RuntimeException("Request to {$url} was redirected to {$location}: only https:// URLs are allowed.");
        }

        return $body;
    }

    /** The first redirect target in a response's headers that is not https, if any. */
    private static function plainHttpRedirect(mixed $headers): ?string
    {
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && preg_match('/^Location:\s*(\S+)/i', $header, $m) === 1
                && str_contains($m[1], '://') && !self::isHttps($m[1])) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Windows PHP builds ship without a CA bundle, so the first fetch there
     * usually fails certificate verification. Say which setting fixes it.
     */
    private static function certificateHint(string $error, string $setting): string
    {
        return preg_match('/certificate|SSL|CA file|issuer/i', $error) === 1
            ? " If this is a certificate problem, point {$setting} in php.ini at a CA bundle such as https://curl.se/ca/cacert.pem."
            : '';
    }
}
