<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;

/**
 * Default cURL-based HTTP client implementation.
 *
 * No external dependencies — uses PHP's built-in cURL extension.
 */
final class HttpClient implements HttpClientInterface
{
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private readonly ?string $userAgent = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function post(string $url, array $data = [], array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, [], $headers);
    }

    /**
     * Execute an HTTP request via cURL.
     *
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $url Full URL.
     * @param array $data Request body data (JSON-encoded for POST).
     * @param array<string, string> $headers Additional headers.
     * @return HttpResponse
     * @throws ApiException On cURL errors or connection failures.
     */
    private function request(string $method, string $url, array $data, array $headers): HttpResponse
    {
        $ch = curl_init();

        $curlHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => $this->userAgent ?? 'Allow2-PHP-SDK/2.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        }

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new ApiException(
                message: "HTTP request failed: {$error}",
                httpStatusCode: 0,
                responseBody: null,
                code: $errno,
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new HttpResponse(
            statusCode: $statusCode,
            body: (string) $body,
        );
    }
}
