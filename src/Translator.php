<?php

declare(strict_types=1);

namespace Trisnawan\Translator;

use InvalidArgumentException;
use JsonException;
use Trisnawan\Translator\Exception\ApiException;
use Trisnawan\Translator\Exception\TranslatorException;
use Trisnawan\Translator\Support\SignatureToken;

/**
 * Submits translation jobs to the Translator REST API (`POST /translate`).
 *
 * Translation is asynchronous: this client only queues the job. The result
 * arrives later through a signed webhook callback (see {@see Webhook}).
 *
 * ```php
 * $translator = new Translator(
 *     'http://localhost:3000',
 *     $accountId, // UUID of the account owning the key
 *     $keyId,     // account_keys id
 *     $secretKey, // account_keys secret (never leaves your server)
 *     'id',       // default source language
 *     'en',       // default target language
 * );
 *
 * $result = $translator->translate('gemini-3.8-flash', 'INV-2026-0001', 'Selamat pagi');
 * ```
 */
class Translator
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $accountId,
        private readonly string $keyId,
        private readonly string $secretKey,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly int $tokenTtl = 300,
        private readonly int $timeout = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Queue a translation job and return the API response data.
     *
     * @param string      $driver           Driver id granted to the account, e.g. "gemini-3.8-flash".
     * @param string      $referenceId      Caller-owned id; echoed back on the callback and bound to the signature token.
     * @param string      $referenceContent Content to translate.
     * @param string|null $from             Source language code; falls back to the constructor default.
     * @param string|null $to               Target language code; falls back to the constructor default.
     *
     * @return array{history_id: string, reference_id: string, driver_id: string, translate_from: string, translate_to: string, status: string, requested_at: string, callback_enabled: bool}
     *
     * @throws ApiException             when the API rejects the request (invalid signature, driver not granted, quota, ...).
     * @throws TranslatorException      when the API cannot be reached.
     * @throws InvalidArgumentException when no language pair can be resolved.
     */
    public function translate(
        string $driver,
        string $referenceId,
        string $referenceContent,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $from ??= $this->from;
        $to ??= $this->to;

        if ($from === null || $to === null) {
            throw new InvalidArgumentException(
                'translate_from and translate_to must be provided in the constructor or per translate() call.'
            );
        }

        return $this->request('/translate', [
            'driver_id' => $driver,
            'translate_from' => $from,
            'translate_to' => $to,
            'reference_id' => $referenceId,
            'reference_content' => $referenceContent,
        ]);
    }

    /**
     * Send a signed request to the API and unwrap the { success, message, data } envelope.
     *
     * @param array<string, string> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        $token = SignatureToken::sign([
            'account_id' => $this->accountId,
            'reference_id' => $payload['reference_id'],
        ], $this->secretKey, $this->tokenTtl);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Translation payload must be valid UTF-8.', 0, $exception);
        }

        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'key_id: ' . $this->keyId,
                'Authorization: Bearer ' . $token,
                'User-Agent: trisnawan/translator-client-php',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($raw === false) {
            throw new TranslatorException('Unable to reach the translator API: ' . $error);
        }

        return $this->unwrap((string) $raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(string $raw, int $statusCode): array
    {
        $response = json_decode($raw, true);

        if (! is_array($response)) {
            throw new ApiException('Translator API returned an unreadable response.', $statusCode);
        }

        if ($statusCode < 200 || $statusCode >= 300 || ($response['success'] ?? false) !== true) {
            throw new ApiException(
                (string) ($response['message'] ?? 'Translator API request failed.'),
                $statusCode,
                (array) ($response['errors'] ?? []),
            );
        }

        return (array) ($response['data'] ?? []);
    }
}