<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\TokenExpiredException;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\CheckResult;
use Allow2\Models\FeedbackCategory;
use Allow2\Models\OAuthTokens;
use Allow2\Models\RequestResult;
use Allow2\Models\RequestType;
use Allow2\Models\VoiceCodePair;

/**
 * Main entry point for the Allow2 PHP SDK (Service API).
 *
 * This is the facade that integrating applications interact with.
 * It wraps OAuth2 authorization, permission checking, request creation,
 * feedback, and offline voice code features into a single coherent API.
 *
 * Each linked service account maps to exactly one Allow2 child — there is
 * no child selector in Service API integrations.
 *
 * Example usage:
 *
 *     $client = new Allow2Client(
 *         clientId: 'your-service-token',
 *         clientSecret: 'your-service-secret',
 *         tokenStorage: new PdoTokenStorage($pdo),
 *         cache: new PdoCache($pdo),
 *     );
 *
 *     // Start OAuth flow
 *     $url = $client->getAuthorizeUrl($userId, 'https://example.com/callback');
 *     header('Location: ' . $url);
 *
 *     // After callback
 *     $client->exchangeCode($userId, $_GET['code'], 'https://example.com/callback');
 *
 *     // Check permissions
 *     $result = $client->check($userId, [['id' => 1, 'log' => true], ['id' => 3, 'log' => true]]);
 *     if ($result->allowed) {
 *         // Allow access
 *     }
 *
 * @see https://developer.allow2.com Allow2 Developer Portal
 */
class Allow2Client
{
    private readonly OAuth2Manager $oauth;
    private readonly PermissionChecker $checker;
    private readonly RequestManager $requests;
    private readonly FeedbackManager $feedback;
    private readonly HttpClientInterface $http;

    /**
     * @param string $clientId Service token from developer.allow2.com.
     * @param string $clientSecret Service secret from developer.allow2.com.
     * @param TokenStorageInterface $tokenStorage Per-user token persistence.
     * @param CacheInterface $cache Permission check result cache.
     * @param HttpClientInterface|null $httpClient Custom HTTP client (default: cURL-based).
     * @param string $apiHost Allow2 API base URL.
     * @param string $serviceHost Allow2 Service base URL.
     * @param int $cacheTtl Permission check cache TTL in seconds (default 60).
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CacheInterface $cache,
        ?HttpClientInterface $httpClient = null,
        private readonly string $apiHost = 'https://api.allow2.com',
        private readonly string $serviceHost = 'https://service.allow2.com',
        private readonly int $cacheTtl = 60,
    ) {
        $this->http = $httpClient ?? new HttpClient();

        $this->oauth = new OAuth2Manager(
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            tokenStorage: $this->tokenStorage,
            httpClient: $this->http,
            apiHost: $this->apiHost,
        );

        $this->checker = new PermissionChecker(
            httpClient: $this->http,
            cache: $this->cache,
            serviceHost: $this->serviceHost,
            cacheTtl: $this->cacheTtl,
        );

        $this->requests = new RequestManager(
            httpClient: $this->http,
            apiHost: $this->apiHost,
        );

        $this->feedback = new FeedbackManager(
            httpClient: $this->http,
            apiHost: $this->apiHost,
        );
    }

    // ──────────────────────────────────────────────────────────
    // OAuth2
    // ──────────────────────────────────────────────────────────

    /**
     * Build the OAuth2 authorization URL to redirect the user to.
     *
     * The user will be asked to link their Allow2 child account to your service.
     *
     * @param string $userId Your application's user ID.
     * @param string $redirectUri Where Allow2 should redirect after authorization.
     * @param string|null $state Optional CSRF state parameter (recommended).
     * @return string The full authorization URL.
     */
    public function getAuthorizeUrl(string $userId, string $redirectUri, ?string $state = null): string
    {
        return $this->oauth->getAuthorizeUrl($userId, $redirectUri, $state);
    }

    /**
     * Exchange an authorization code for OAuth2 tokens.
     *
     * Call this in your redirect_uri callback handler.
     *
     * @param string $userId Your application's user ID.
     * @param string $code The authorization code from the callback query string.
     * @param string $redirectUri Must match the redirect_uri used in getAuthorizeUrl().
     * @return OAuthTokens The token set (also stored automatically).
     * @throws ApiException If the token exchange fails.
     */
    public function exchangeCode(string $userId, string $code, string $redirectUri): OAuthTokens
    {
        return $this->oauth->exchangeCode($userId, $code, $redirectUri);
    }

    /**
     * Check whether a user's Allow2 account pairing is still valid.
     *
     * @param string $userId Your application's user ID.
     * @return bool True if the account is still linked.
     */
    public function checkPairingStatus(string $userId): bool
    {
        return $this->oauth->checkPairingStatus($userId);
    }

    /**
     * Unpair a user by removing their stored OAuth2 tokens.
     *
     * @param string $userId Your application's user ID.
     */
    public function unpair(string $userId): void
    {
        $this->oauth->unpair($userId);
    }

    /**
     * Check whether the user has stored OAuth2 tokens (i.e., has been paired).
     *
     * @param string $userId Your application's user ID.
     * @return bool True if tokens exist.
     */
    public function isPaired(string $userId): bool
    {
        return $this->oauth->hasTokens($userId);
    }

    // ──────────────────────────────────────────────────────────
    // Permission Checking
    // ──────────────────────────────────────────────────────────

    /**
     * Check permissions for a user's linked Allow2 child account.
     *
     * Activities can be specified in several formats:
     *
     * 1. Array of associative arrays (Allow2 API / WP plugin format):
     *    [['id' => 1, 'log' => true], ['id' => 3, 'log' => true]]
     *
     * 2. Simple array of activity IDs (auto-expanded with log=true):
     *    [1, 3, 8]
     *
     * 3. Legacy associative format:
     *    [1 => 1, 3 => 1, 8 => 1]
     *
     * Common activity IDs: 1 (Internet), 3 (Gaming), 6 (Social), 8 (Screen Time).
     *
     * @param string $userId Your application's user ID.
     * @param array $activities Activities to check (see format options above).
     * @param string|null $timezone IANA timezone (e.g., "Australia/Brisbane").
     * @return CheckResult Detailed permission check result.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function check(string $userId, array $activities, ?string $timezone = null): CheckResult
    {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->checker->check($accessToken, $userId, $activities, $timezone);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Convenience: check if all specified activities are currently allowed.
     *
     * @param string $userId Your application's user ID.
     * @param int[] $activityIds Activity IDs to check.
     * @return bool True if all activities are allowed.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function isAllowed(string $userId, array $activityIds): bool
    {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->checker->isAllowed($accessToken, $userId, $activityIds);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    // ──────────────────────────────────────────────────────────
    // Requests (More Time, Day Type Change, Ban Lift)
    // ──────────────────────────────────────────────────────────

    /**
     * Request more time for an activity.
     *
     * @param string $userId Your application's user ID.
     * @param int $activityId The activity to request more time for.
     * @param int $minutes Number of additional minutes.
     * @param string $message Optional message to the parent.
     * @return RequestResult Request ID and status secret for polling.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function requestMoreTime(
        string $userId,
        int $activityId,
        int $minutes,
        string $message = '',
    ): RequestResult {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->requests->requestMoreTime($accessToken, $userId, $activityId, $minutes, $message);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Request a day type change (e.g., treat today as a weekend).
     *
     * @param string $userId Your application's user ID.
     * @param int $dayTypeId The desired day type ID.
     * @param string $message Optional message to the parent.
     * @return RequestResult Request ID and status secret for polling.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function requestDayTypeChange(
        string $userId,
        int $dayTypeId,
        string $message = '',
    ): RequestResult {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->requests->requestDayTypeChange($accessToken, $userId, $dayTypeId, $message);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Request lifting a ban on an activity.
     *
     * @param string $userId Your application's user ID.
     * @param int $activityId The banned activity.
     * @param string $message Optional message to the parent.
     * @return RequestResult Request ID and status secret for polling.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function requestBanLift(
        string $userId,
        int $activityId,
        string $message = '',
    ): RequestResult {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->requests->requestBanLift($accessToken, $userId, $activityId, $message);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Poll the status of a pending request.
     *
     * @param string $requestId The request ID from a create method.
     * @param string $statusSecret The status secret from a create method.
     * @return string Current status: "pending", "approved", or "denied".
     * @throws ApiException On API errors.
     */
    public function getRequestStatus(string $requestId, string $statusSecret): string
    {
        return $this->requests->getRequestStatus($requestId, $statusSecret);
    }

    /**
     * Obtain a temporary request token from the Allow2 API.
     *
     * Used by integrations (e.g., WordPress plugin) that manage the request
     * creation flow themselves and need a temp token independently.
     *
     * @param string $userId Your application's user ID.
     * @param string|null $nonce Optional nonce for idempotency.
     * @return array Raw response array with 'tempToken' and 'secretToken' keys.
     * @throws TokenExpiredException If no tokens exist or refresh fails.
     * @throws ApiException On API errors.
     */
    public function getRequestToken(string $userId, ?string $nonce = null): array
    {
        $accessToken = $this->getAccessToken($userId);
        return $this->requests->getTempToken($accessToken, $nonce);
    }

    // ──────────────────────────────────────────────────────────
    // Voice Codes (Offline Approval)
    // ──────────────────────────────────────────────────────────

    /**
     * Generate an offline voice code challenge-response pair.
     *
     * The challenge is displayed to the child, who reads it to the parent.
     * The parent's Allow2 app computes the response; the child enters it.
     *
     * @param string $secret The child's pairing secret. Must be provided by the integration.
     * @param RequestType $type The type of request.
     * @param int $activityId The activity ID.
     * @param int $minutes Minutes requested (for MoreTime type, ignored otherwise).
     * @param string|null $userId Your application's user ID (reserved for future use).
     * @param string|null $date Override date for testing (Y-m-d format).
     * @return VoiceCodePair Challenge to display and expected response.
     */
    public function generateVoiceChallenge(
        string $secret,
        RequestType $type,
        int $activityId,
        int $minutes = 0,
        ?string $userId = null,
        ?string $date = null,
    ): VoiceCodePair {
        if ($secret === '') {
            throw new \InvalidArgumentException(
                'A pairing secret is required for voice code generation. '
                . 'This should be stored during the OAuth2 pairing process.'
            );
        }

        return VoiceCode::generate($secret, $type, $activityId, $minutes, $date);
    }

    /**
     * Verify a voice code response entered by the child.
     *
     * @param string $secret The child's pairing secret.
     * @param string $challenge The challenge code that was displayed.
     * @param string $response The response code entered by the child.
     * @param string|null $userId Your application's user ID (reserved for future use).
     * @param string|null $date Override date for testing (Y-m-d format).
     * @return bool True if the response is valid.
     */
    public function verifyVoiceResponse(
        string $secret,
        string $challenge,
        string $response,
        ?string $userId = null,
        ?string $date = null,
    ): bool {
        if ($secret === '') {
            throw new \InvalidArgumentException('A pairing secret is required for voice code verification.');
        }

        return VoiceCode::verify($secret, $challenge, $response, $date);
    }

    // ──────────────────────────────────────────────────────────
    // Feedback
    // ──────────────────────────────────────────────────────────

    /**
     * Submit feedback from the user.
     *
     * @param string $userId Your application's user ID.
     * @param FeedbackCategory $category Feedback category.
     * @param string $message The feedback message.
     * @return string The discussion ID for the created thread.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function submitFeedback(
        string $userId,
        FeedbackCategory $category,
        string $message,
    ): string {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->feedback->submit($accessToken, $userId, $category, $message);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Load all feedback discussion threads for the user.
     *
     * @param string $userId Your application's user ID.
     * @return array List of discussion threads.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function loadFeedback(string $userId): array
    {
        $accessToken = $this->getAccessToken($userId);

        try {
            return $this->feedback->load($accessToken, $userId);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    /**
     * Reply to an existing feedback discussion thread.
     *
     * @param string $userId Your application's user ID.
     * @param string $discussionId The discussion thread ID.
     * @param string $message The reply message.
     * @throws UnpairedException If the account is no longer linked.
     * @throws TokenExpiredException If token refresh fails.
     * @throws ApiException On API errors.
     */
    public function replyToFeedback(string $userId, string $discussionId, string $message): void
    {
        $accessToken = $this->getAccessToken($userId);

        try {
            $this->feedback->reply($accessToken, $userId, $discussionId, $message);
        } catch (UnpairedException $e) {
            $this->handleUnpaired($userId);
            throw $e; // unreachable — handleUnpaired() is never-returning
        }
    }

    // ──────────────────────────────────────────────────────────
    // Internal
    // ──────────────────────────────────────────────────────────

    /**
     * Get a valid access token for the user, auto-refreshing if needed.
     *
     * @param string $userId Your application's user ID.
     * @return string A valid access token.
     * @throws TokenExpiredException If no tokens exist or refresh fails.
     */
    private function getAccessToken(string $userId): string
    {
        return $this->oauth->getAccessToken($userId);
    }

    /**
     * Handle an unpaired response: clear tokens and throw.
     *
     * @param string $userId Your application's user ID.
     * @throws UnpairedException Always thrown.
     * @return never
     */
    private function handleUnpaired(string $userId): never
    {
        $this->tokenStorage->delete($userId);
        throw new UnpairedException($userId);
    }
}
