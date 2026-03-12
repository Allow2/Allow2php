# Allow2 PHP Service SDK v2

[![Packagist version](https://img.shields.io/packagist/v/allow2/allow2-service.svg?style=flat-square)](https://packagist.org/packages/allow2/allow2-service)
[![PHP versions](https://img.shields.io/packagist/php-v/allow2/allow2-service.svg?style=flat-square)](https://packagist.org/packages/allow2/allow2-service)
[![CI](https://img.shields.io/github/actions/workflow/status/Allow2/Allow2php-service/ci.yml?style=flat-square)](https://github.com/Allow2/Allow2php-service/actions)

Official Allow2 Parental Freedom **Service SDK** for PHP — for web services with user accounts (WordPress sites, SaaS, forums, etc.).

This is a **Service SDK** — it runs on your web server, not on a child's device. Following industry standard practice (Stripe, Firebase, Auth0), Allow2 maintains separate Device and Service SDKs. It handles OAuth2 pairing, permission checking, all 3 request types, voice codes, and feedback via the Allow2 Service API.

| | |
|---|---|
| **Package** | `allow2/allow2-service` |
| **Targets** | PHP 8.1+ |
| **Extensions** | `curl`, `json`, `hash` |
| **Language** | PHP (OOP, PSR-4) |

## Requirements

- PHP 8.1 or later
- `ext-curl` -- HTTP requests
- `ext-json` -- JSON encoding/decoding
- `ext-hash` -- HMAC-SHA256 for voice codes

## Installation

```bash
composer require allow2/allow2-service
```

## Quick Start

1. Register your application at [developer.allow2.com](https://developer.allow2.com) and note your `clientId` and `clientSecret`.

2. Create an `Allow2Client` and wire up the OAuth2 flow:

```php
use Allow2\Allow2Client;
use Allow2\Storage\PdoTokenStorage;
use Allow2\Cache\FileCache;

$allow2 = new Allow2Client(
    clientId: 'YOUR_SERVICE_TOKEN',
    clientSecret: 'YOUR_SERVICE_SECRET',
    tokenStorage: new PdoTokenStorage(new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass')),
    cache: new FileCache('/tmp/allow2-cache'),
);

// Step 1: Redirect user to Allow2 for pairing
$authorizeUrl = $allow2->getAuthorizeUrl(
    userId: $currentUserId,
    redirectUri: 'https://yourapp.com/allow2/callback',
    state: $csrfToken,
);
header('Location: ' . $authorizeUrl);
exit;

// Step 2: Handle the callback
$tokens = $allow2->exchangeCode(
    userId: $currentUserId,
    code: $_GET['code'],
    redirectUri: 'https://yourapp.com/allow2/callback',
);

// Step 3: Check permissions on every request
$result = $allow2->check($currentUserId, [1, 3]); // Internet + Gaming

if (!$result->allowed) {
    // Show block page
} else {
    $remaining = $result->getRemainingSeconds(1);
    // Proceed normally, optionally showing countdown
}
```

## Key Concept: One Account = One Child

The Service API links a specific user account on your site to exactly one Allow2 child. There is no child selector -- the identity is established at OAuth2 pairing time when the parent selects which child this account belongs to.

This means:

- Each user account on your site maps to one Allow2 child
- The parent performs pairing once per account, selecting the child
- All subsequent permission checks for that account apply to that child automatically
- Parent/admin accounts can be excluded from checking entirely

## OAuth2 Flow

### Step 1: Authorization

Redirect the user to Allow2 so their parent can pair the account:

```php
$state = bin2hex(random_bytes(16));
$_SESSION['allow2_state'] = $state;

$authorizeUrl = $allow2->getAuthorizeUrl(
    userId: $currentUserId,
    redirectUri: 'https://yourapp.com/callback',
    state: $state,
);
header('Location: ' . $authorizeUrl);
exit;
```

### Step 2: Code Exchange

Handle the OAuth2 callback:

```php
if ($_GET['state'] !== $_SESSION['allow2_state']) {
    throw new \RuntimeException('Invalid state parameter');
}

$tokens = $allow2->exchangeCode(
    userId: $currentUserId,
    code: $_GET['code'],
    redirectUri: 'https://yourapp.com/callback',
);
// Tokens are stored automatically via the configured TokenStorage
```

### Step 3: Token Refresh

Tokens expire. The SDK handles refresh automatically -- when you call `check()` or any other method that requires authentication, the SDK detects expired tokens and refreshes them transparently, persisting the updated tokens via your `TokenStorageInterface`.

## Permission Checking

Check permissions on every page load or API request:

```php
// Simple format -- flat array of activity IDs (auto-expanded with log: true)
$result = $allow2->check($userId, [1, 3, 8]); // Internet + Gaming + Screen Time

// Full format -- explicit log flags
$result = $allow2->check($userId, [
    ['id' => 1, 'log' => true],   // Internet
    ['id' => 8, 'log' => true],   // Screen Time
], 'Australia/Sydney');

if (!$result->allowed) {
    echo "Blocked! Day type: " . $result->todayDayType->name;
    foreach ($result->activities as $activity) {
        if ($activity->banned) {
            echo $activity->name . " is banned";
        } elseif (!$activity->timeBlockAllowed) {
            echo $activity->name . " outside allowed hours";
        }
    }
} else {
    $remaining = $result->getRemainingSeconds(1);
    // Optionally show countdown in the UI
    renderPage(['remaining' => $remaining]);
}
```

### Convenience Check

```php
// Returns true only if ALL specified activities are allowed
$allowed = $allow2->isAllowed($userId, [1, 3]);
```

### Caching

The SDK caches check results internally using your configured `CacheInterface`. The default TTL is 60 seconds and can be overridden via the constructor:

```php
$allow2 = new Allow2Client(
    clientId: 'YOUR_TOKEN',
    clientSecret: 'YOUR_SECRET',
    tokenStorage: $storage,
    cache: $cache,
    cacheTtl: 30, // cache for 30 seconds
);
```

## Requests

Children can request changes directly from your site. There are three types of request, and the philosophy is simple: the child drives the configuration, the parent just approves or denies.

### More Time

```php
$request = $allow2->requestMoreTime(
    userId: $userId,
    activityId: 3,       // Gaming
    minutes: 30,
    message: 'Almost done with this level!',
);

echo "Request ID: " . $request->requestId;

// Poll for parent response
$status = $allow2->getRequestStatus($request->requestId, $request->statusSecret);

if ($status === 'approved') {
    echo "Approved!";
} elseif ($status === 'denied') {
    echo "Request denied.";
} else {
    echo "Still waiting...";
}
```

### Day Type Change

```php
$request = $allow2->requestDayTypeChange(
    userId: $userId,
    dayTypeId: 2,        // Weekend
    message: 'We have a day off school today.',
);
```

### Ban Lift

```php
$request = $allow2->requestBanLift(
    userId: $userId,
    activityId: 6,       // Social Media
    message: 'I finished all my homework. Can the ban be lifted?',
);
```

## Voice Codes (Offline Approval)

Even though the child is on a website (online), the **parent** may have no internet -- perhaps they are at work with no signal, or their phone is flat. Voice codes let the parent approve a request by reading a short numeric code over the phone or in person.

### Generate a Challenge

```php
use Allow2\Models\RequestType;

$pair = $allow2->generateVoiceChallenge(
    userId: $userId,
    type: RequestType::MoreTime,
    activityId: 3,       // Gaming
    minutes: 30,         // in 5-min increments
    secret: $pairingSecret,
);

echo "Challenge code: {$pair->challenge}";
echo "Read this to your parent. Ask them for the response code.";
```

### Verify the Response

```php
$isValid = $allow2->verifyVoiceResponse(
    userId: $userId,
    challenge: $pair->challenge,
    response: $parentResponseCode,
    secret: $pairingSecret,
);

if ($isValid) {
    echo "Approved! Extra time granted.";
} else {
    echo "Invalid code. Please try again.";
}
```

The codes use HMAC-SHA256 challenge-response, date-bound (expires at midnight). The format is compact enough to read over a phone call: a spaced challenge and a 6-digit response.

## Feedback

Let users submit bug reports and feature requests directly to you, the developer:

```php
use Allow2\Models\FeedbackCategory;

// Submit feedback -- returns the discussion ID
$discussionId = $allow2->submitFeedback(
    userId: $userId,
    category: FeedbackCategory::Bug,
    message: 'The block page appears even when I have time remaining.',
);

// Load feedback threads
$threads = $allow2->loadFeedback($userId);

// Reply to a thread
$allow2->replyToFeedback($userId, $discussionId, 'This happens every Tuesday.');
```

## Architecture

| Module | Purpose |
|--------|---------|
| **Allow2Client** | Main entry point, orchestrates all operations |
| **OAuth2Manager** | OAuth2 authorize, code exchange, token refresh |
| **PermissionChecker** | Permission checks with caching |
| **RequestManager** | All 3 request types with temp token + status polling |
| **VoiceCode** | HMAC-SHA256 challenge-response for offline approval |
| **FeedbackManager** | Submit, load, and reply to feedback threads |

### Models

| Model | Purpose |
|-------|---------|
| `CheckResult` | Parsed permission check response with per-activity status |
| `Activity` | Single activity's allowed/blocked state and remaining time |
| `DayType` | Current and upcoming day type information |
| `OAuthTokens` | Access token, refresh token, expiry (public readonly properties) |
| `RequestResult` | Request ID, status secret, and status with helper methods |
| `VoiceCodePair` | Challenge and expected response pair |
| `RequestType` | Enum: `MoreTime`, `DayTypeChange`, `BanLift` |
| `FeedbackCategory` | Enum: `Bug`, `FeatureRequest`, `NotWorking`, `Other` |

### Exceptions

| Exception | When |
|-----------|------|
| `Allow2Exception` | Base exception for all SDK errors |
| `ApiException` | HTTP or API-level errors |
| `TokenExpiredException` | Token refresh failed (re-pairing needed) |
| `UnpairedException` | No valid tokens for this user (401/403 from API) |

## Token Storage

The SDK persists OAuth2 tokens automatically via the `TokenStorageInterface` you provide at construction. Three built-in adapters are included.

### PdoTokenStorage (recommended)

```php
use Allow2\Storage\PdoTokenStorage;

$pdo = new \PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$tokenStorage = new PdoTokenStorage($pdo);

// Creates the table if it doesn't exist
// Store and retrieve tokens
$tokenStorage->store($userId, $tokens);
$tokens = $tokenStorage->retrieve($userId);
$tokenStorage->delete($userId);
$tokenStorage->exists($userId); // bool
```

### SessionTokenStorage (development/prototyping)

```php
use Allow2\Storage\SessionTokenStorage;

$tokenStorage = new SessionTokenStorage();
$tokenStorage->store($userId, $tokens);   // stored in $_SESSION
```

### FileTokenStorage

```php
use Allow2\Storage\FileTokenStorage;

$tokenStorage = new FileTokenStorage('/var/lib/allow2/tokens');
```

### Custom Storage (Laravel, WordPress, etc.)

Implement `TokenStorageInterface` to integrate with your framework:

```php
use Allow2\TokenStorageInterface;
use Allow2\Models\OAuthTokens;

class LaravelTokenStorage implements TokenStorageInterface
{
    public function store(string $userId, OAuthTokens $tokens): void
    {
        DB::table('allow2_tokens')->updateOrInsert(
            ['user_id' => $userId],
            [
                'access_token'  => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at'    => $tokens->expiresAt,
            ]
        );
    }

    public function retrieve(string $userId): ?OAuthTokens
    {
        $record = DB::table('allow2_tokens')->where('user_id', $userId)->first();
        if (!$record) return null;

        return new OAuthTokens(
            accessToken:  $record->access_token,
            refreshToken: $record->refresh_token,
            expiresAt:    $record->expires_at,
        );
    }

    public function delete(string $userId): void
    {
        DB::table('allow2_tokens')->where('user_id', $userId)->delete();
    }

    public function exists(string $userId): bool
    {
        return DB::table('allow2_tokens')->where('user_id', $userId)->exists();
    }
}
```

## Device Operational Lifecycle

The Allow2 Service API follows a 7-step lifecycle, adapted for server-side web applications:

1. **Pairing** (one-time) -- OAuth2 flow redirects to Allow2 where the parent selects which child this account belongs to. Tokens are stored per user in your database.

2. **Child Identification** (automatic) -- one account = one child. The child's identity is established at pairing time and encoded in the OAuth2 tokens. No child selector is needed.

3. **Parent Access** -- admin or parent accounts on your site are simply excluded from Allow2 checking. No special Allow2 flow is needed.

4. **Permission Checks** (continuous) -- check on every page load or API request, server-side. Pass `log: true` to record usage. The response includes remaining time, daily limits, time blocks, day types, and bans.

5. **Warnings & Countdowns** -- the server calculates remaining time from the check result. Your frontend displays countdowns and warnings as appropriate (e.g. "5 minutes remaining").

6. **Requests** -- child requests changes (more time, day type change, ban lift) from your site. The parent approves or denies from their Allow2 app. For parents without internet, voice codes provide offline approval.

7. **Feedback** -- bug reports and feature requests are sent directly to you, the developer, via the Allow2 feedback system. This gives you a built-in support channel without building one yourself.

## Offline Operation

"Offline" for a web app sounds contradictory -- but the approval channel can be offline even when the child's browser is online.

### When the parent has no internet

The child is on your website (online), but their parent's phone may have no signal. Voice codes solve this:

1. Child clicks "Request More Time" on your site
2. Your server generates a spaced challenge code
3. Child reads the code to their parent (phone call, in person)
4. Parent enters it into their Allow2 app (works offline) and reads back the 6-digit response
5. Child enters the response on your site
6. Your server verifies the HMAC-SHA256 response and grants the time

### When your server can't reach Allow2

If the Allow2 API is temporarily unreachable:

- **Cache the last check result** -- continue enforcing the last known permissions for a short grace period
- **Deny by default** -- after the grace period, block access to prevent bypass
- **Queue requests** -- store request attempts and replay them when connectivity resumes

## License

Copyright 2017-2026 Allow2 Pty Ltd. All rights reserved.

See [LICENSE](LICENSE) for details.
