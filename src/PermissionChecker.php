<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\CheckResult;

/**
 * Checks child permissions via the Allow2 Service API.
 *
 * Results are cached per user for a configurable TTL to avoid
 * excessive API calls during a single page load or request cycle.
 */
final class PermissionChecker
{
    /** Default cache TTL in seconds. */
    private const DEFAULT_CACHE_TTL = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $serviceHost,
        private readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {}

    /**
     * Check permissions for a user's linked child account.
     *
     * Activities can be specified in two formats:
     *
     * 1. Array of associative arrays (Allow2 API format):
     *    [['id' => 1, 'log' => true], ['id' => 3, 'log' => true]]
     *
     * 2. Simple array of activity IDs (auto-expanded with log=true):
     *    [1, 3]
     *
     * The legacy associative format [1 => 1, 3 => 1] is also supported.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID (for cache keying).
     * @param array $activities Activity IDs to check (see format options above).
     * @param string|null $timezone IANA timezone (e.g., "Australia/Brisbane"). Null for server default.
     * @param bool $log Whether to log usage (default true).
     * @param bool $useCache Whether to use cached results (default true).
     * @return CheckResult The permission check result.
     * @throws UnpairedException If the API returns 401/403 (account unlinked).
     * @throws ApiException On other API failures.
     */
    public function check(
        string $accessToken,
        string $userId,
        array $activities,
        ?string $timezone = null,
        bool $log = true,
        bool $useCache = true,
    ): CheckResult {
        $activities = $this->normalizeActivities($activities);

        // Check cache first
        if ($useCache) {
            $cacheKey = $this->buildCacheKey($userId, $activities);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                try {
                    $data = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
                    return CheckResult::fromApiResponse($data);
                } catch (\JsonException) {
                    $this->cache->delete($cacheKey);
                }
            }
        }

        $payload = [
            'access_token' => $accessToken,
            'activities' => $activities,
            'log' => $log,
        ];

        if ($timezone !== null) {
            $payload['tz'] = $timezone;
        }

        $response = $this->httpClient->post(
            $this->serviceHost . '/serviceapi/check',
            $payload,
        );

        // 401/403 = unpaired
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
                message: 'Permission check failed: HTTP ' . $response->statusCode,
                httpStatusCode: $response->statusCode,
                responseBody: $body,
            );
        }

        $data = $response->json();
        $result = CheckResult::fromApiResponse($data);

        // Cache the raw response
        if ($useCache) {
            $cacheKey = $this->buildCacheKey($userId, $activities);
            $this->cache->set($cacheKey, json_encode($data, JSON_THROW_ON_ERROR), $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Convenience method: check if specific activities are all allowed.
     *
     * @param string $accessToken Valid OAuth2 access token.
     * @param string $userId The integrating application's user ID.
     * @param int[] $activityIds Activity IDs to check.
     * @param string|null $timezone IANA timezone.
     * @return bool True if all specified activities are allowed.
     * @throws UnpairedException If the API returns 401/403.
     * @throws ApiException On other API failures.
     */
    public function isAllowed(
        string $accessToken,
        string $userId,
        array $activityIds,
        ?string $timezone = null,
    ): bool {
        // Accept plain IDs — normalizeActivities in check() handles the conversion
        $result = $this->check($accessToken, $userId, $activityIds, $timezone);

        foreach ($result->activities as $activity) {
            if (!$activity->allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Invalidate the cached check result for a user.
     *
     * @param string $userId The integrating application's user ID.
     * @param array<int, int> $activities The same activities array used in check().
     */
    public function invalidateCache(string $userId, array $activities): void
    {
        $cacheKey = $this->buildCacheKey($userId, $activities);
        $this->cache->delete($cacheKey);
    }

    /**
     * Normalize activities into the standard array-of-associative-arrays format.
     *
     * Accepts:
     * - [['id' => 1, 'log' => true], ...] — passed through as-is
     * - [1, 3, 8] — expanded to [['id' => 1, 'log' => true], ...]
     * - [1 => 1, 3 => 1] — legacy associative format, converted
     *
     * @param array $activities Activities in any supported format.
     * @return array<int, array{id: int, log: bool}> Normalized activities.
     */
    private function normalizeActivities(array $activities): array
    {
        if ($activities === []) {
            return [];
        }

        // Detect format by inspecting the first element
        $firstKey = array_key_first($activities);
        $firstValue = $activities[$firstKey];

        // Format 1: array of associative arrays [['id' => 1, 'log' => true], ...]
        if (is_array($firstValue) && isset($firstValue['id'])) {
            return array_values($activities);
        }

        // Determine if this is a sequential list [1, 3, 8] or an associative map [1 => 1, 3 => 1].
        // A sequential list has keys 0, 1, 2, ... and values are the activity IDs.
        // An associative map has activity IDs as keys and flags (typically 1) as values.
        $keys = array_keys($activities);
        $isSequential = ($keys === range(0, count($keys) - 1));

        $normalized = [];
        if ($isSequential) {
            // Format 2: simple list of IDs [1, 3, 8]
            foreach ($activities as $id) {
                $normalized[] = ['id' => (int) $id, 'log' => true];
            }
        } else {
            // Format 3: legacy associative [1 => 1, 3 => 1]
            foreach ($activities as $id => $flag) {
                $normalized[] = ['id' => (int) $id, 'log' => (bool) $flag];
            }
        }

        return $normalized;
    }

    /**
     * Build a deterministic cache key for a user + activities combination.
     *
     * @param string $userId
     * @param array $activities Normalized activities array.
     * @return string
     */
    private function buildCacheKey(string $userId, array $activities): string
    {
        $ids = array_map(fn($a) => is_int($a) ? $a : $a['id'], $activities);
        sort($ids);
        $actSuffix = implode('_', $ids);

        return "allow2_check_{$userId}_{$actSuffix}";
    }
}
