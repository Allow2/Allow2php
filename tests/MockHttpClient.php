<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\HttpClientInterface;
use Allow2\HttpResponse;

/**
 * Mock HTTP client for testing. Returns preconfigured responses
 * in FIFO order and records all requests for inspection.
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var HttpResponse[] */
    private array $responses = [];

    /** @var array{url: string, method: string, data: array, headers: array}[] */
    private array $requests = [];

    /**
     * Queue a response to be returned by the next request.
     *
     * @param int $statusCode HTTP status code.
     * @param array $body Response body (will be JSON-encoded).
     */
    public function addResponse(int $statusCode, array $body): void
    {
        $this->responses[] = new HttpResponse(
            statusCode: $statusCode,
            body: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Queue a raw HttpResponse object.
     */
    public function addRawResponse(HttpResponse $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $url, array $data = [], array $headers = []): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'method' => 'POST',
            'data' => $data,
            'headers' => $headers,
        ];

        return $this->dequeueResponse();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'method' => 'GET',
            'data' => [],
            'headers' => $headers,
        ];

        return $this->dequeueResponse();
    }

    /**
     * Get the last recorded request.
     *
     * @return array{url: string, method: string, data: array, headers: array}
     */
    public function getLastRequest(): array
    {
        if (empty($this->requests)) {
            throw new \RuntimeException('No requests recorded.');
        }

        return end($this->requests);
    }

    /**
     * Get a specific request by index (0-based).
     *
     * @return array{url: string, method: string, data: array, headers: array}
     */
    public function getRequest(int $index): array
    {
        if (!isset($this->requests[$index])) {
            throw new \RuntimeException("No request at index {$index}.");
        }

        return $this->requests[$index];
    }

    /**
     * Get the total number of requests made.
     */
    public function getRequestCount(): int
    {
        return count($this->requests);
    }

    /**
     * Get all recorded requests.
     *
     * @return array{url: string, method: string, data: array, headers: array}[]
     */
    public function getAllRequests(): array
    {
        return $this->requests;
    }

    /**
     * Reset all queued responses and recorded requests.
     */
    public function reset(): void
    {
        $this->responses = [];
        $this->requests = [];
    }

    private function dequeueResponse(): HttpResponse
    {
        if (empty($this->responses)) {
            throw new \RuntimeException(
                'MockHttpClient: no response queued. Call addResponse() before making requests.'
            );
        }

        return array_shift($this->responses);
    }
}
