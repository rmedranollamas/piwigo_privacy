<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Authorized media target ready for derivative generation or direct serving.
 */
final class ResolvedMedia
{
    public const KIND_ORIGINAL = 'original';
    public const KIND_REPRESENTATIVE = 'representative';
    public const KIND_FORMAT = 'format';
    public const KIND_DERIVATIVE = 'derivative';
    public const KIND_DERIVATIVE_THEME = 'derivative_theme';

    /**
     * @param array<string, mixed>|null $elementInfo
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $serveRelPath,
        public readonly ?array $elementInfo,
        public readonly ?ParsedDerivative $parsedDerivative,
        public readonly bool $enabledHigh,
        public readonly bool $grantedViaPshare = false,
    ) {
    }
}
