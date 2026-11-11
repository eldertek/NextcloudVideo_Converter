<?php

declare(strict_types=1);

namespace OCA\Video_Converter\Command;

use OCA\Video_Converter\Service\ConversionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ConvertCommand extends Command {
    public function __construct(
        private ConversionService $conversionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('video_converter:convert')
            ->setDescription('Convert a video file or extract audio inside a Nextcloud user folder')
            ->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user ID owning the file')
            ->addArgument('source', InputArgument::REQUIRED, 'Relative path to the source video inside the user files (e.g. /Videos/sample.mp4)')
            ->addArgument('format', InputArgument::OPTIONAL, 'Target format: mp4, webm, mkv, mov, m4v, avi, mp3, m4a', 'mp4')
            ->addOption('preset', 'p', InputOption::VALUE_REQUIRED, 'FFmpeg preset (ultrafast..veryslow)', 'medium')
            ->addOption('codec', 'c', InputOption::VALUE_REQUIRED, 'Video codec (auto, h264, h265, vp8, vp9, mpeg4, copy)', 'auto')
            ->addOption('vbitrate', null, InputOption::VALUE_REQUIRED, 'Target video bitrate in kbps', '')
            ->addOption('abitrate', null, InputOption::VALUE_REQUIRED, 'Target audio bitrate in kbps', '192')
            ->addOption('scale', 's', InputOption::VALUE_REQUIRED, 'Target resolution scale (e.g. 1920:-2, 1280:-2, 854:-2, 640:-2)', '')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite destination file if it already exists')
            ->addOption('no-faststart', null, InputOption::VALUE_NONE, 'Disable MP4/MOV/M4A faststart movflags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = (string)$input->getArgument('user');
        $source = (string)$input->getArgument('source');
        $format = (string)$input->getArgument('format');

        $options = [
            'format' => $format,
            'preset' => (string)$input->getOption('preset'),
            'codec' => (string)$input->getOption('codec'),
            'vbitrate' => (string)$input->getOption('vbitrate'),
            'abitrate' => (string)$input->getOption('abitrate'),
            'scale' => (string)$input->getOption('scale'),
            'overwrite' => (bool)$input->getOption('overwrite'),
            'faststart' => !(bool)$input->getOption('no-faststart'),
        ];

        try {
            $result = $this->conversionService->convert($userId, $source, $options);
            $output->writeln(sprintf(
                '<info>Converted "%s" -> "%s" (fileid=%d, size=%d bytes, mime=%s)</info>',
                $result['source'],
                $result['output'],
                $result['fileid'],
                $result['size'],
                $result['mimetype']
            ));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Conversion failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
