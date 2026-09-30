<?php

declare(strict_types=1);

namespace Trisnawan\Translator;

use Trisnawan\Translator\Exception\WebhookException;
use Trisnawan\Translator\Support\SignatureToken;

/**
 * Verifies and reads the signed callback the Translator API posts once a job
 * finishes (see §6 of the API documentation).
 *
 * {@see Webhook::validData()} performs every check — key id header, HS256
 * signature, token expiry, reference id binding and status whitelist — then
 * either returns the callback payload or throws a {@see WebhookException}.
 * Reply `2xx` only when no exception was thrown, otherwise the backend will
 * schedule a retry.
 *
 * ```php
 * try {
 *     $data = (new Webhook())->validData($keyId, $secretKey);
 * } catch (WebhookException $e) {
 *     http_response_code(401);
 *     exit($e->getMessage());
 * }
 *
 * if ($data['status'] === 'translated') {
 *     saveTranslation($data['reference_id'], $data['translated_content']);
 * }
 *
 * http_response_code(200);
 * ```
 */
class Webhook
{
    private const STATUSES = ['translated', 'failed'];

    /**
     * @param array<string, mixed>|null $server  Server variables ({@see $_SERVER}); defaults to $_SERVER.
     * @param string|null               $rawBody Raw JSON body; defaults to php://input.
     */
    public function __construct(
        private readonly ?array $server = null,
        private readonly ?string $rawBody = null,
    ) {
    }

    /**
     * Validate the incoming callback and return its payload.
     *
     * With multiple API keys, read the `key_id` header first to resolve the
     * matching secret key, then pass both here.
     *
     * @param string $keyId     `account_keys` id the callback was sent with.
     * @param string $secretKey Matching secret key, owned by the client.
     *
     * @return array{status: string, translate_from: string, translate_to: string, reference_id: string, translated_content: string|null, translated_at: string|null}
     *
     * @throws WebhookException when the callback cannot be trusted or is malformed.
     */
    public function validData(string $keyId, string $secretKey): array
    {
        $this->assertKeyId($keyId);

        $claims = SignatureToken::verify($this->bearerToken(), $secretKey);
        $data = $this->jsonBody();

        $this->assertReferenceId($data, $claims);
        $this->assertStatus($data);

        return $data;
    }

    private function assertKeyId(string $keyId): void
    {
        $received = $this->header('key_id');

        if ($received === null) {
            throw new WebhookException('Callback request is missing the key_id header.');
        }

        if (! hash_equals($keyId, $received)) {
            throw new WebhookException('Callback key_id does not match the expected API key.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $claims
     */
    private function assertReferenceId(array $data, array $claims): void
    {
        $referenceId = $data['reference_id'] ?? null;

        if (! is_string($referenceId) || $referenceId === '') {
            throw new WebhookException('Callback payload is missing reference_id.');
        }

        $signedReferenceId = $claims['reference_id'] ?? null;

        if (! is_string($signedReferenceId) || ! hash_equals($signedReferenceId, $referenceId)) {
            throw new WebhookException('Callback reference_id does not match the signed token.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertStatus(array $data): void
    {
        if (! in_array($data['status'] ?? null, self::STATUSES, true)) {
            throw new WebhookException('Callback status must be "translated" or "failed".');
        }
    }

    private function header(string $name): ?string
    {
        $server = $this->server ?? $_SERVER;
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        $value = $server[$key] ?? $server['REDIRECT_' . $key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function bearerToken(): string
    {
        $authorization = $this->header('Authorization') ?? '';

        if (! preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            throw new WebhookException('Callback request is missing a Bearer signature token.');
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $data = json_decode($this->rawBody ?? (string) file_get_contents('php://input'), true);

        if (! is_array($data)) {
            throw new WebhookException('Callback body is not a valid JSON object.');
        }

        return $data;
    }
}