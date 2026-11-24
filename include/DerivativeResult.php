<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Output of DerivativeGenerator pointing to the verified local relative path to serve.
 */
final class DerivativeResult
{
    public function __construct(
        public readonly string $relPath,
        public readonly bool $generatedNow,
        public readonly bool $unchangedFallback = false,
    ) {
    }
}
