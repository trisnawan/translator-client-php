<?php

declare(strict_types=1);

namespace Trisnawan\Translator\Exception;

use RuntimeException;

/**
 * Base exception for every error raised by this library, so callers can catch
 * a single type.
 */
class TranslatorException extends RuntimeException
{
}
