<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\TokenExpiredException;
use Allow2\Models\OAuthTokens;

/**
 * Handles the OAuth2 authorization flow for Allow2 Service API integrations.
 *
 * Flow:
 * 1. Redirect user to getAuthorizeUrl()
 * 2. User links their Allow2 child account
 * 3. Allow2 redirects back with an authorization code
 * 4. Call exchangeCode() to get access + refresh tokens
 * 5. Tokens auto-refresh via refreshTokens() when needed
 */
final class OAuth2Manager
{
    /** Seconds before actual expiry to trigger a refresh. */
    private const REFRESH_BUFFER = 300;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiHost,
    ) {}

    /**
     * Build the OAuth2 authorization URL to redirect the user to.
     *
     * @param string $userId The integrating application's user ID.
     * @param string $redirectUri Where Allow2 should redirect after authorization.
     * @param string|null $state Optional CSRF state parameter.
     * @return string The full authorization URL.
     */
    public function getAuthorizeUrl(string $userId, string $redirectUri, ?string $state = null): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'user_id' => $userId,
        ];

        if ($state !== null) {
            $params['state'] = $state;
        }

        return $this->apiHost . '/oauth2/authorize?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @param string $userId The integrating application's user ID.
     * @param string $code The authorization code from the callback.
     * @param string $redirectUri Must match the redirect_uri used in getAuthorizeUrl().
     * @return OAuthTokens The token set.
     * @throws ApiException If the token exchange fails.
     */
    public function exchangeCode(string $userId, string $code, string $redirectUri): OAuthTokens
    {
        $response = $this->httpClient->post(
            $this->apiHost . '/oauth2/token',
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        );

        if (!$response->isSuccess()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\JsonException) {
            }
            throw new ApiException(
                message: 'OAuth2 token exchange failed: ' . ($body['error_description'] ?? $response->body),
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }

        $tokens = OAuthTokens::fromApiResponse($response->json());
        $this->tokenStorage->store($userId, $tokens);

        return $tokens;
    }

    /**
     * Get a valid access token for the user, refreshing if necessary.
     *
     * @param string $userId The integrating application's user ID.
     * @return string A valid access token.
     * @throws TokenExpiredException If no tokens exist or refresh fails.
     */
    public function getAccessToken(string $userId): string
    {
        $tokens = $this->tokenStorage->retrieve($userId);

        if ($tokens === null) {
            throw new TokenExpiredException($userId, 'No tokens stored for user. Authorization required.');
        }

        if (!$tokens->isExpired(self::REFRESH_BUFFER)) {
            return $tokens->accessToken;
        }

        return $this->refreshTokens($userId, $tokens)->accessToken;
    }

    /**
     * Refresh the OAuth2 tokens using the refresh token.
     *
     * @param string $userId The integrating application's user ID.
     * @param OAuthTokens $tokens The current (expired) token set.
     * @return OAuthTokens The refreshed token set.
     * @throws TokenExpiredException If the refresh fails.
     */
    public function refreshTokens(string $userId, OAuthTokens $tokens): OAuthTokens
    {
        $response = $this->httpClient->post(
            $this->apiHost . '/oauth2/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokens->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        );

        if (!$response->isSuccess()) {
            $this->tokenStorage->delete($userId);
            throw new TokenExpiredException(
                userId: $userId,
                message: 'OAuth2 token refresh failed. Re-authorization required.',
                previous: new ApiException(
                    message: 'Refresh token request returned HTTP ' . $response->statusCode,
                    httpStatusCode: $response->statusCode,
                ),
            );
        }

        $newTokens = OAuthTokens::fromApiResponse($response->json());
        $this->tokenStorage->store($userId, $newTokens);

        return $newTokens;
    }

    /**
     * Check whether a user's service account pairing is still valid.
     *
     * @param string $userId The integrating application's user ID.
     * @return bool True if still paired, false otherwise.
     */
    public function checkPairingStatus(string $userId): bool
    {
        if (!$this->tokenStorage->exists($userId)) {
            return false;
        }

        try {
            $accessToken = $this->getAccessToken($userId);
        } catch (TokenExpiredException) {
            return false;
        }

        $response = $this->httpClient->post(
            $this->apiHost . '/oauth2/checkStatus',
            [
                'access_token' => $accessToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        );

        if (!$response->isSuccess()) {
            return false;
        }

        try {
            $data = $response->json();
            return (bool) ($data['paired'] ?? $data['active'] ?? false);
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Unpair a user by deleting their stored tokens.
     *
     * @param string $userId The integrating application's user ID.
     */
    public function unpair(string $userId): void
    {
        $this->tokenStorage->delete($userId);
    }

    /**
     * Check whether tokens exist for the given user.
     */
    public function hasTokens(string $userId): bool
    {
        return $this->tokenStorage->exists($userId);
    }
}
