<?php

declare(strict_types=1);

namespace OCA\Video_Converter\BackgroundJob;

use OCA\Video_Converter\Service\ConversionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class ConvertVideoJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ConversionService $conversionService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        if (!is_array($argument)) {
            return;
        }

        $userId = isset($argument['userId']) ? (string)$argument['userId'] : '';
        $source = isset($argument['source']) ? (string)$argument['source'] : '';
        $options = isset($argument['options']) && is_array($argument['options']) ? $argument['options'] : [];

        if ($userId === '' || $source === '') {
            return;
        }

        try {
            $result = $this->conversionService->convert($userId, $source, $options);
            $this->logger->info('Background video conversion completed', [
                'app' => 'video_converter',
                'user' => $userId,
                'source' => $source,
                'output' => $result['output'],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Background video conversion failed: ' . $e->getMessage(), [
                'app' => 'video_converter',
                'user' => $userId,
                'source' => $source,
                'exception' => $e,
            ]);
        }
    }
}
