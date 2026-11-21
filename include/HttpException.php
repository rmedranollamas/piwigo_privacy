<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

use RuntimeException;

/**
 * Exception carrying an HTTP status code for rejected or malformed requests.
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message, $statusCode);
    }
}
