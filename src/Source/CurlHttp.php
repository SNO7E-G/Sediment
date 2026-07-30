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
 * directory would be rude. Whichever route is taken, TLS verification stays on.
 */
final class CurlHttp implements Http
{
    public function __construct(
        private readonly string $userAgent = 'Sediment (+https://github.com/SNO7E-G/Sediment)',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function get(string $url): string
    {
        return function_exists('curl_init') ? $this->viaCurl($url) : $this->viaStream($url);
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
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException("Request to {$url} failed: {$error}");
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

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("Request to {$url} failed (no curl extension, and the stream wrapper could not fetch it)");
        }

        return $body;
    }
}
