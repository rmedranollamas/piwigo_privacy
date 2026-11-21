<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

use DerivativeParams;

/**
 * Parsed derivative target extracted from an i.php or _data/i/ request.
 */
final class ParsedDerivative
{
    public function __construct(
        public readonly string $postfix,
        public readonly string $type,
        public readonly DerivativeParams $params,
        public readonly string $srcRelPath,
        public readonly string $derivativeRelPath,
        public readonly string $ext,
        public readonly bool $isRepresentativeSource,
        public readonly bool $isThemeOrPluginSource,
    ) {
    }
}
