<?php

declare(strict_types=1);

namespace Allow2;

/**
 * PSR-style HTTP client interface for Allow2 API calls.
 *
 * Implementations must handle JSON encoding/decoding and return
 * an HttpResponse value object.
 */
interface HttpClientInterface
{
    /**
     * Send a POST request.
     *
     * @param string $url Full URL to send to.
     * @param array $data Body data (will be JSON-encoded by the implementation).
     * @param array<string, string> $headers Additional headers.
     * @return HttpResponse
     */
    public function post(string $url, array $data = [], array $headers = []): HttpResponse;

    /**
     * Send a GET request.
     *
     * @param string $url Full URL to send to.
     * @param array<string, string> $headers Additional headers.
     * @return HttpResponse
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
