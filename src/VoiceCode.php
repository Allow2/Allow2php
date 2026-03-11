<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Models\RequestType;
use Allow2\Models\VoiceCodePair;

/**
 * HMAC-SHA256 challenge-response system for offline voice code approval.
 *
 * When a child is offline, they can read a challenge code to the parent
 * (over phone/voice). The parent's Allow2 app computes the matching
 * response code. The child enters it to get temporary approval.
 *
 * Challenge format: T A MM NN
 *   T  = Request type (0 = more time, 1 = day type change, 2 = ban lift)
 *   A  = Activity ID (0-99)
 *   MM = Minutes in 5-minute increments (0-99, so 0-495 minutes)
 *   NN = Random nonce (0-99)
 *
 * Response: First 6 digits of HMAC-SHA256(secret, challenge + date)
 * Date-bound: expires at midnight in the child's timezone.
 */
final class VoiceCode
{
    /**
     * Generate a challenge-response pair for offline approval.
     *
     * @param string $secret The child's pairing secret (shared between parent and child device).
     * @param RequestType $type The type of request.
     * @param int $activityId The activity ID (0-99).
     * @param int $minutes Minutes requested (rounded to nearest 5-min increment, max 495).
     * @param string|null $date Date string in Y-m-d format (default: today). Used for date-binding.
     * @return VoiceCodePair The challenge to display and the expected response.
     */
    public static function generate(
        string $secret,
        RequestType $type,
        int $activityId,
        int $minutes = 0,
        ?string $date = null,
    ): VoiceCodePair {
        $date ??= date('Y-m-d');

        // T: request type code (0-2)
        $t = $type->voiceCode();

        // A: activity ID clamped to 0-99
        $a = max(0, min(99, $activityId));

        // MM: minutes in 5-minute increments, clamped to 0-99
        $mm = max(0, min(99, (int) round($minutes / 5)));

        // NN: random nonce 0-99
        $nn = random_int(0, 99);

        $challenge = sprintf('%d %02d %02d %02d', $t, $a, $mm, $nn);
        $expectedResponse = self::computeResponse($secret, $challenge, $date);

        return new VoiceCodePair(
            challenge: $challenge,
            expectedResponse: $expectedResponse,
        );
    }

    /**
     * Verify a voice code response against a challenge.
     *
     * @param string $secret The child's pairing secret.
     * @param string $challenge The challenge code that was displayed.
     * @param string $response The response code entered by the child.
     * @param string|null $date Date string (default: today). Must match when the challenge was generated.
     * @return bool True if the response is valid.
     */
    public static function verify(
        string $secret,
        string $challenge,
        string $response,
        ?string $date = null,
    ): bool {
        $date ??= date('Y-m-d');
        $expected = self::computeResponse($secret, $challenge, $date);

        return hash_equals($expected, $response);
    }

    /**
     * Decode a challenge string into its component parts.
     *
     * @param string $challenge The challenge in "T A MM NN" or "T AA MM NN" format.
     * @return array{type: int, activityId: int, minutes: int, nonce: int}|null Null if invalid format.
     */
    public static function decodeChallenge(string $challenge): ?array
    {
        // Normalize whitespace
        $parts = preg_split('/\s+/', trim($challenge));

        if ($parts === false || count($parts) !== 4) {
            return null;
        }

        return [
            'type' => (int) $parts[0],
            'activityId' => (int) $parts[1],
            'minutes' => (int) $parts[2] * 5,
            'nonce' => (int) $parts[3],
        ];
    }

    /**
     * Compute the 6-digit response for a challenge + date.
     *
     * @param string $secret Shared secret.
     * @param string $challenge Challenge string.
     * @param string $date Date in Y-m-d format.
     * @return string 6-digit numeric response string.
     */
    private static function computeResponse(string $secret, string $challenge, string $date): string
    {
        $message = $challenge . $date;
        $hmac = hash_hmac('sha256', $message, $secret);

        // Take first 8 hex chars (32 bits), convert to integer, modulo 1,000,000 for 6 digits
        $truncated = hexdec(substr($hmac, 0, 8));
        $code = $truncated % 1_000_000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }
}
