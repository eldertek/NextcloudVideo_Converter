<?php

declare(strict_types=1);

namespace OCA\Video_Converter\Controller;

use InvalidArgumentException;
use OCA\Video_Converter\BackgroundJob\ConvertVideoJob;
use OCA\Video_Converter\Service\ConversionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

class ConversionController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private ConversionService $conversionService,
        private IJobList $jobList,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function formats(): JSONResponse {
        return new JSONResponse([
            'status' => 'success',
            'formats' => ConversionService::OUTPUT_FORMATS,
            'inputExtensions' => ConversionService::INPUT_EXTENSIONS,
            'presets' => ConversionService::PRESETS,
            'ffmpegAvailable' => $this->conversionService->findExecutable('ffmpeg') !== null,
        ]);
    }

    /**
     * Primary REST endpoint for synchronous or background video/audio conversion.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function convert(): JSONResponse {
        return $this->handleConversionRequest();
    }

    /**
     * Legacy endpoint kept for backward compatibility with older scripts.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function convertHere(): JSONResponse {
        return $this->handleConversionRequest();
    }

    private function handleConversionRequest(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => 'Authentication required.'],
            ], Http::STATUS_UNAUTHORIZED);
        }

        $source = trim((string)($this->request->getParam('source', '')));
        if ($source === '') {
            $directory = trim((string)($this->request->getParam('directory', '/')));
            $nameOfFile = trim((string)($this->request->getParam('nameOfFile', '')));
            if ($nameOfFile !== '') {
                $source = rtrim($directory, '/') . '/' . ltrim($nameOfFile, '/');
            }
        }

        if ($source === '') {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => 'Missing "source" file path parameter.'],
            ], Http::STATUS_BAD_REQUEST);
        }

        $rawOptions = [
            'format' => $this->request->getParam('format', $this->request->getParam('type', 'mp4')),
            'preset' => $this->request->getParam('preset', 'medium'),
            'codec' => $this->request->getParam('codec', 'auto'),
            'vbitrate' => $this->request->getParam('vbitrate', ''),
            'abitrate' => $this->request->getParam('abitrate', '192'),
            'scale' => $this->request->getParam('scale', ''),
            'priority' => $this->request->getParam('priority', 0),
        ];

        if ($this->request->getParam('faststart') !== null) {
            $rawOptions['faststart'] = $this->request->getParam('faststart');
        } elseif ($this->request->getParam('movflags') !== null) {
            $rawOptions['movflags'] = $this->request->getParam('movflags');
        }

        if ($this->request->getParam('overwrite') !== null) {
            $rawOptions['overwrite'] = $this->request->getParam('overwrite');
        }

        $async = filter_var($this->request->getParam('async', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $sourceFile = $this->conversionService->resolveSourceFile($user->getUID(), $source);
            $normalizedOptions = $this->conversionService->normalizeOptions($rawOptions);

            if ($async) {
                $cleanSource = $this->conversionService->normalizeRelativePath($source);
                $this->jobList->add(ConvertVideoJob::class, [
                    'userId' => $user->getUID(),
                    'source' => $cleanSource,
                    'options' => $normalizedOptions,
                ]);

                return new JSONResponse([
                    'status' => 'queued',
                    'data' => [
                        'message' => 'Conversion queued in background job.',
                        'source' => $cleanSource,
                        'sourceName' => $sourceFile->getName(),
                        'format' => $normalizedOptions['format'],
                    ],
                ], Http::STATUS_ACCEPTED);
            }

            $result = $this->conversionService->convert($user->getUID(), $source, $normalizedOptions);

            return new JSONResponse([
                'status' => 'success',
                'data' => $result,
            ], Http::STATUS_OK);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => $e->getMessage()],
            ], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => $e->getMessage()],
            ], Http::STATUS_NOT_FOUND);
        } catch (NotPermittedException $e) {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => $e->getMessage()],
            ], Http::STATUS_FORBIDDEN);
        } catch (Throwable $e) {
            return new JSONResponse([
                'status' => 'error',
                'data' => ['message' => $e->getMessage()],
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
