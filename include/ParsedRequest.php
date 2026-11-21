<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Validated, normalized representation of an incoming media or derivative request.
 */
final class ParsedRequest
{
    public function __construct(
        public readonly string $normalizedPath,
        public readonly ?int $imgId,
        public readonly bool $isDerivativeRequest,
        public readonly bool $ajaxload,
        public readonly bool $cacheBuster,
        public readonly ?string $pshareKey,
        public readonly bool $isExternalParentPath = false,
    ) {
    }
}
