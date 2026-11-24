<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Orchestrates request parsing, authentication, access checks, derivative generation,
 * and HTTP response emission for get.php.
 */
final class FrontController
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $user
     * @param callable(string): object|null $imageFactory
     */
    public static function handle(
        array $server,
        Config $config,
        array &$user,
        string $rootPath,
        MediaResponder $responder,
        string $absoluteRootUrl,
        ?callable $imageFactory = null,
    ): void {
        try {
            Authenticator::authenticateAndReleaseLock($server, $user);

            $parsedRequest = RequestParser::parse($server, $config);
            $media = AccessVerifier::verify($parsedRequest, $config, $user, $rootPath);

            if ($media->kind === ResolvedMedia::KIND_DERIVATIVE
                || $media->kind === ResolvedMedia::KIND_DERIVATIVE_THEME
            ) {
                if ($media->parsedDerivative === null) {
                    throw new HttpException(500, 'Missing parsed derivative metadata');
                }
                if (!class_exists('pwg_image', false) && is_file($rootPath . 'admin/include/image.class.php')) {
                    include_once $rootPath . 'admin/include/image.class.php';
                }

                $result = DerivativeGenerator::ensureGenerated(
                    $media->parsedDerivative,
                    $media->elementInfo,
                    $rootPath,
                    $config,
                    $imageFactory
                );

                AccessVerifier::assertDerivativeFallbackPermitted($media, $result);

                if ($parsedRequest->ajaxload) {
                    $relNoDot = $result->relPath;
                    if (str_starts_with($relNoDot, './')) {
                        $relNoDot = substr($relNoDot, 2);
                    } elseif (str_starts_with($relNoDot, '../')) {
                        $relNoDot = substr($relNoDot, 3);
                    }
                    $relNoDot = ltrim($relNoDot, '/');
                    $ajaxUrl = rtrim($absoluteRootUrl, '/') . '/' . $relNoDot;
                    if ($config->rewriteUrls && $media->elementInfo !== null && isset($media->elementInfo['id'])) {
                        $ajaxUrl = UrlRewriter::modifyUrl($ajaxUrl, (int) $media->elementInfo['id'], $config, $absoluteRootUrl);
                    }
                    if ($media->grantedViaPshare && $parsedRequest->pshareKey !== null) {
                        $ajaxUrl = UrlRewriter::appendPshareToken($ajaxUrl, $parsedRequest->pshareKey);
                    }
                    $responder->sendAjaxDerivativeJson($ajaxUrl);
                    return;
                }

                $responder->serveFile($result->relPath, $rootPath, $config, $server, $parsedRequest->cacheBuster);
                return;
            }

            $allowedExts = $media->kind === ResolvedMedia::KIND_FORMAT
                ? array_values(array_unique(array_merge($config->formatExt, $config->fileExt)))
                : null;

            $responder->serveFile(
                $media->serveRelPath,
                $rootPath,
                $config,
                $server,
                $parsedRequest->cacheBuster,
                $allowedExts
            );
        } catch (HttpException $e) {
            Authenticator::releaseSessionLock();
            self::logDenial($e, $server, $config);
            $responder->sendError($e, $config, $absoluteRootUrl);
        } catch (\Throwable $e) {
            Authenticator::releaseSessionLock();
            $internal = new HttpException(500, 'Internal error: ' . $e->getMessage());
            self::logDenial($internal, $server, $config);
            $responder->sendError(new HttpException(500, 'Internal Server Error'), $config, $absoluteRootUrl);
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function logDenial(HttpException $e, array $server, Config $config): void
    {
        if (!$config->debug && !$config->logDenials) {
            return;
        }

        global $logger;
        $safeMsg = str_replace(["\r", "\n"], ' ', $e->getMessage());
        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '-';

        if (is_object($logger) && method_exists($logger, 'debug')) {
            $logger->debug($e->statusCode . ' ' . $safeMsg, 'piwigo_privacy', ['ip' => $remote]);
        } else {
            error_log('piwigo_privacy ' . $e->statusCode . ' ' . $safeMsg . ' ' . $remote);
        }
    }
}
