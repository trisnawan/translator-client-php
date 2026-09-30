<?php

declare(strict_types=1);

namespace Trisnawan\Translator\Exception;

/**
 * Thrown when an incoming callback cannot be trusted (missing headers, wrong
 * key id, bad signature, expired token, tampered payload, unknown status).
 */
class WebhookException extends TranslatorException
{
}
