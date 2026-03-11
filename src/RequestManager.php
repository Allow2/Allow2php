<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\RequestResult;
use Allow2\Models\RequestType;

/**
 * Manages child requests (more time, day type change, ban lift) via the Allow2 Service API.
 *
 * Flow:
 * 1. Obtain a temporary request token via /request/tempToken
 * 2. Create the request via /request/createRequest
 * 3. Poll status via /request/{id}/status using the statusSecret
 */
final class RequestManager
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiHost,
    ) {}

    /**
     * Request more time for an activity.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param int $activityId The activity to request more time for.
     * @param int $minutes Number of additional minutes requested.
     * @param string $message Optional message to the parent.
     * @return RequestResult The created request with ID and status secret.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function requestMoreTime(
        string $accessToken,
        string $userId,
        int $activityId,
        int $minutes,
        string $message = '',
    ): RequestResult {
        return $this->createRequest($accessToken, $userId, [
            'type' => RequestType::MoreTime->value,
            'activityId' => $activityId,
            'minutes' => $minutes,
            'message' => $message,
        ]);
    }

    /**
     * Request a day type change.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param int $dayTypeId The desired day type ID.
     * @param string $message Optional message to the parent.
     * @return RequestResult The created request.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function requestDayTypeChange(
        string $accessToken,
        string $userId,
        int $dayTypeId,
        string $message = '',
    ): RequestResult {
        return $this->createRequest($accessToken, $userId, [
            'type' => RequestType::DayTypeChange->value,
            'dayTypeId' => $dayTypeId,
            'message' => $message,
        ]);
    }

    /**
     * Request lifting a ban on an activity.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param int $activityId The banned activity to request lifting.
     * @param string $message Optional message to the parent.
     * @return RequestResult The created request.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function requestBanLift(
        string $accessToken,
        string $userId,
        int $activityId,
        string $message = '',
    ): RequestResult {
        return $this->createRequest($accessToken, $userId, [
            'type' => RequestType::BanLift->value,
            'activityId' => $activityId,
            'message' => $message,
        ]);
    }

    /**
     * Poll the status of an existing request.
     *
     * @param string $requestId The request ID from createRequest.
     * @param string $statusSecret The status secret from createRequest.
     * @return string The current status: "pending", "approved", or "denied".
     * @throws ApiException On API failures.
     */
    public function getRequestStatus(string $requestId, string $statusSecret): string
    {
        $response = $this->httpClient->get(
            $this->apiHost . '/request/' . urlencode($requestId) . '/status',
            ['X-Status-Secret' => $statusSecret],
        );

        if (!$response->isSuccess()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to get request status: HTTP ' . $response->statusCode,
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }

        $data = $response->json();
        return (string) ($data['status'] ?? 'pending');
    }

    /**
     * Obtain a temporary request token from /request/tempToken.
     *
     * Returns the raw API response array containing 'tempToken' and 'secretToken' keys
     * (or 'token'/'requestToken' depending on the API version).
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string|null $nonce Optional nonce for idempotency.
     * @return array The raw response array with token data.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function getTempToken(string $accessToken, ?string $nonce = null): array
    {
        $payload = ['access_token' => $accessToken];

        if ($nonce !== null) {
            $payload['nonce'] = $nonce;
        }

        $tempTokenResponse = $this->httpClient->post(
            $this->apiHost . '/request/tempToken',
            $payload,
        );

        if ($tempTokenResponse->isUnauthorized()) {
            throw new UnpairedException('');
        }

        if (!$tempTokenResponse->isSuccess()) {
            $body = null;
            try {
                $body = $tempTokenResponse->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to obtain request token: HTTP ' . $tempTokenResponse->statusCode,
                httpStatusCode: $tempTokenResponse->statusCode,
                responseBody: $body,
            );
        }

        return $tempTokenResponse->json();
    }

    /**
     * Obtain a temporary token for creating a request, then create it.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param array $payload The request-specific payload.
     * @return RequestResult
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    private function createRequest(string $accessToken, string $userId, array $payload): RequestResult
    {
        // Step 1: Get a temporary request token
        $tempData = $this->getTempToken($accessToken);
        $tempToken = $tempData['token'] ?? $tempData['requestToken'] ?? $tempData['tempToken'] ?? null;

        if ($tempToken === null) {
            throw new ApiException(
                message: 'Temporary request token not found in API response',
                httpStatusCode: 200,
                responseBody: $tempData,
            );
        }

        // Step 2: Create the request
        $payload['requestToken'] = $tempToken;
        $payload['access_token'] = $accessToken;

        $createResponse = $this->httpClient->post(
            $this->apiHost . '/request/createRequest',
            $payload,
        );

        if ($createResponse->isUnauthorized()) {
            throw new UnpairedException($userId);
        }

        if (!$createResponse->isSuccess()) {
            $body = null;
            try {
                $body = $createResponse->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to create request: HTTP ' . $createResponse->statusCode,
                httpStatusCode: $createResponse->statusCode,
                responseBody: $body,
            );
        }

        return RequestResult::fromApiResponse($createResponse->json());
    }
}
