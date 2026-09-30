<?php

declare(strict_types=1);

namespace Trisnawan\Translator\Exception;

use Throwable;

/**
 * Thrown when the Translator API is reachable but rejects the request:
 * validation error, invalid key/signature, quota exceeded, broker down, ...
 */
class ApiException extends TranslatorException
{
    /**
     * @param int                        $statusCode HTTP status code returned by the API.
     * @param array<int, array<string, mixed>> $errors     Detailed errors from the API envelope.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
