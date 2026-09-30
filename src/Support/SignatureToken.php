<?php

declare(strict_types=1);

namespace Trisnawan\Translator\Support;

use InvalidArgumentException;
use Trisnawan\Translator\Exception\WebhookException;

/**
 * Minimal JWT (HS256) helper for the API-key signature tokens described in the
 * Translator API documentation (§2.2): signed with the account key secret,
 * bound to a single reference id.
 *
 * @internal Shared by {@see \Trisnawan\Translator\Translator} (sign) and
 *           {@see \Trisnawan\Translator\Webhook} (verify).
 */
final class SignatureToken
{
    /**
     * Sign a token with the given claims (iat/exp are added automatically).
     *
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, string $secretKey, int $ttlSeconds = 300): string
    {
        $now = time();

        $header = self::base64UrlEncode(self::encodeJson(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(self::encodeJson($claims + [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]));

        return $header . '.' . $payload . '.' . self::signature($header . '.' . $payload, $secretKey);
    }

    /**
     * Verify a token's signature and expiry, then return its claims.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookException when the token is malformed, forged or expired.
     */
    public static function verify(string $token, string $secretKey): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new WebhookException('Signature token is malformed.');
        }

        [$header, $payload, $signature] = $segments;

        if (! hash_equals(self::signature($header . '.' . $payload, $secretKey), $signature)) {
            throw new WebhookException('Signature token is invalid.');
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);

        if (! is_array($claims)) {
            throw new WebhookException('Signature token payload is not a valid JSON object.');
        }

        if ((int) ($claims['exp'] ?? 0) < time()) {
            throw new WebhookException('Signature token has expired.');
        }

        return $claims;
    }

    private static function signature(string $data, string $secretKey): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $data, $secretKey, true));
    }

    /**
     * @param mixed $value
     */
    private static function encodeJson($value): string
    {
        $json = json_encode($value);

        if ($json === false) {
            throw new InvalidArgumentException('Signature token claims must be valid UTF-8.');
        }

        return $json;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
