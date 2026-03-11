<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\FeedbackCategory;

/**
 * Manages feedback submission and discussion threads via the Allow2 API.
 *
 * Feedback allows users to report issues, request features, or communicate
 * with the Allow2 Parental Freedom support team.
 */
final class FeedbackManager
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiHost,
    ) {}

    /**
     * Submit new feedback.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param FeedbackCategory $category The feedback category.
     * @param string $message The feedback message.
     * @return string The discussion ID for the created feedback thread.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function submit(
        string $accessToken,
        string $userId,
        FeedbackCategory $category,
        string $message,
    ): string {
        $response = $this->httpClient->post(
            $this->apiHost . '/feedback/submit',
            [
                'access_token' => $accessToken,
                'category' => $category->value,
                'message' => $message,
            ],
        );

        if ($response->isUnauthorized()) {
            throw new UnpairedException($userId);
        }

        if (!$response->isSuccess()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to submit feedback: HTTP ' . $response->statusCode,
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }

        $data = $response->json();
        return (string) ($data['discussionId'] ?? $data['feedbackId'] ?? $data['id'] ?? '');
    }

    /**
     * Load all feedback discussions for the authenticated user.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @return array List of feedback discussion threads.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function load(string $accessToken, string $userId): array
    {
        $response = $this->httpClient->get(
            $this->apiHost . '/feedback/load',
            ['Authorization' => 'Bearer ' . $accessToken],
        );

        if ($response->isUnauthorized()) {
            throw new UnpairedException($userId);
        }

        if (!$response->isSuccess()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to load feedback: HTTP ' . $response->statusCode,
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }

        $data = $response->json();
        return $data['discussions'] ?? $data['feedback'] ?? [];
    }

    /**
     * Reply to an existing feedback discussion thread.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param string $discussionId The discussion thread ID to reply to.
     * @param string $message The reply message.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function reply(
        string $accessToken,
        string $userId,
        string $discussionId,
        string $message,
    ): void {
        $response = $this->httpClient->post(
            $this->apiHost . '/feedback/' . urlencode($discussionId) . '/reply',
            [
                'access_token' => $accessToken,
                'message' => $message,
            ],
        );

        if ($response->isUnauthorized()) {
            throw new UnpairedException($userId);
        }

        if (!$response->isSuccess()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'Failed to reply to feedback: HTTP ' . $response->statusCode,
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }
    }
}
