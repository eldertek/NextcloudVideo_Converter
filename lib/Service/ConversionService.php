<?php

declare(strict_types=1);

namespace OCA\Video_Converter\Service;

use InvalidArgumentException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ConversionService {
    /** @var array<string, array{category: string, label: string, video_codec: string|null, audio_codec: string, faststart: bool}> */
    public const OUTPUT_FORMATS = [
        'mp4' => [
            'category' => 'video',
            'label' => 'MP4 (H.264 / AAC)',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'faststart' => true,
        ],
        'webm' => [
            'category' => 'video',
            'label' => 'WebM (VP8 / Vorbis)',
            'video_codec' => 'libvpx',
            'audio_codec' => 'libvorbis',
            'faststart' => false,
        ],
        'mkv' => [
            'category' => 'video',
            'label' => 'MKV (Matroska)',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'faststart' => false,
        ],
        'mov' => [
            'category' => 'video',
            'label' => 'MOV (QuickTime)',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'faststart' => true,
        ],
        'm4v' => [
            'category' => 'video',
            'label' => 'M4V (MPEG-4 Video)',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'faststart' => true,
        ],
        'avi' => [
            'category' => 'video',
            'label' => 'AVI (MPEG-4 / MP3)',
            'video_codec' => 'mpeg4',
            'audio_codec' => 'libmp3lame',
            'faststart' => false,
        ],
        'mp3' => [
            'category' => 'audio',
            'label' => 'MP3 (Audio extraction)',
            'video_codec' => null,
            'audio_codec' => 'libmp3lame',
            'faststart' => false,
        ],
        'm4a' => [
            'category' => 'audio',
            'label' => 'M4A (AAC audio extraction)',
            'video_codec' => null,
            'audio_codec' => 'aac',
            'faststart' => true,
        ],
    ];

    /** @var string[] */
    public const INPUT_EXTENSIONS = [
        'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'vob',
        'ts', 'mts', 'm2ts', 'mpg', 'mpeg', 'flv', 'wmv',
        '3gp', 'ogv', 'asf', 'divx',
    ];

    /** @var string[] */
    public const PRESETS = [
        'ultrafast', 'superfast', 'veryfast', 'faster',
        'fast', 'medium', 'slow', 'slower', 'veryslow',
    ];

    /** @var array<string, string> */
    public const VIDEO_CODECS = [
        'auto' => 'auto',
        '1' => 'auto',
        'h264' => 'libx264',
        'libx264' => 'libx264',
        'h265' => 'libx265',
        'hevc' => 'libx265',
        'libx265' => 'libx265',
        'vp8' => 'libvpx',
        'libvpx' => 'libvpx',
        'vp9' => 'libvpx-vp9',
        'libvpx-vp9' => 'libvpx-vp9',
        'mpeg4' => 'mpeg4',
        'copy' => 'copy',
    ];

    /** @var array<string, string|null> */
    public const SCALE_PRESETS = [
        '' => null,
        '-1' => null,
        'original' => null,
        '3840:-2' => '3840:-2',
        '3840:-1' => '3840:-2',
        '2560:-2' => '2560:-2',
        '2560:-1' => '2560:-2',
        '1920:-2' => '1920:-2',
        '1920:-1' => '1920:-2',
        '1280:-2' => '1280:-2',
        '1280:-1' => '1280:-2',
        '854:-2' => '854:-2',
        '854:-1' => '854:-2',
        '720:-2' => '720:-2',
        '720:-1' => '720:-2',
        '640:-2' => '640:-2',
        '640:-1' => '640:-2',
        '480:-2' => '480:-2',
        '480:-1' => '480:-2',
        '426:-2' => '426:-2',
        '360:-2' => '360:-2',
        '360:-1' => '360:-2',
    ];

    public function __construct(
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Validate and resolve the user relative path without traversal.
     */
    public function normalizeRelativePath(string $path): string {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid file path.');
        }

        $replaced = str_replace('\\', '/', trim($path));
        $segments = explode('/', $replaced);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not permitted.');
            }
            $clean[] = $segment;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('File path cannot be empty.');
        }

        return '/' . implode('/', $clean);
    }

    /**
     * Validate source file node and ensure it is a supported video/media input.
     */
    public function resolveSourceFile(string $userId, string $sourceRelativePath): File {
        $cleanPath = $this->normalizeRelativePath($sourceRelativePath);
        $userFolder = $this->rootFolder->getUserFolder($userId);

        if (!$userFolder->nodeExists($cleanPath)) {
            throw new NotFoundException('Source file not found: ' . $cleanPath);
        }

        $node = $userFolder->get($cleanPath);
        if (!($node instanceof File)) {
            throw new InvalidArgumentException('Source path is not a regular file.');
        }

        if (!$node->isReadable()) {
            throw new NotPermittedException('Source file is not readable.');
        }

        $ext = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        $mime = strtolower((string)$node->getMimeType());
        if (!in_array($ext, self::INPUT_EXTENSIONS, true) && !str_starts_with($mime, 'video/')) {
            throw new InvalidArgumentException(sprintf('Unsupported input file type "%s" (%s).', $ext, $mime));
        }

        $parent = $node->getParent();
        if (!$parent->isCreatable() && !$parent->isUpdatable()) {
            throw new NotPermittedException('Target directory is not writable.');
        }

        return $node;
    }

    /**
     * Validate conversion options before running synchronously or queuing.
     *
     * @param array<string, mixed> $options
     * @return array{
     *   format: string,
     *   preset: string,
     *   codec: string,
     *   vbitrate: int|null,
     *   abitrate: int,
     *   scale: string|null,
     *   faststart: bool,
     *   overwrite: bool,
     *   priority: int
     * }
     */
    public function normalizeOptions(array $options): array {
        $format = strtolower(trim((string)($options['format'] ?? $options['type'] ?? 'mp4')));
        $format = ltrim($format, '.');
        if (!isset(self::OUTPUT_FORMATS[$format])) {
            throw new InvalidArgumentException('Unsupported output format: ' . $format);
        }

        $preset = strtolower(trim((string)($options['preset'] ?? 'medium')));
        if ($preset === '' || $preset === '1') {
            $preset = 'medium';
        }
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException('Unsupported encoding preset: ' . $preset);
        }

        $codecKey = strtolower(trim((string)($options['codec'] ?? 'auto')));
        if ($codecKey === '') {
            $codecKey = 'auto';
        }
        if (!isset(self::VIDEO_CODECS[$codecKey])) {
            throw new InvalidArgumentException('Unsupported video codec: ' . $codecKey);
        }
        $codec = self::VIDEO_CODECS[$codecKey];

        $rawVBitrate = trim((string)($options['vbitrate'] ?? ''));
        $vbitrate = null;
        if ($rawVBitrate !== '' && $rawVBitrate !== '-1' && $rawVBitrate !== 'auto') {
            if (!ctype_digit($rawVBitrate)) {
                throw new InvalidArgumentException('Video bitrate must be an integer in kbps.');
            }
            $parsed = (int)$rawVBitrate;
            if ($parsed < 64 || $parsed > 200000) {
                throw new InvalidArgumentException('Video bitrate must be between 64 and 200000 kbps.');
            }
            $vbitrate = $parsed;
        }

        $rawABitrate = trim((string)($options['abitrate'] ?? '192'));
        $abitrate = 192;
        if ($rawABitrate !== '') {
            if (!ctype_digit($rawABitrate)) {
                throw new InvalidArgumentException('Audio bitrate must be an integer in kbps.');
            }
            $parsedAudio = (int)$rawABitrate;
            if ($parsedAudio < 32 || $parsedAudio > 512) {
                throw new InvalidArgumentException('Audio bitrate must be between 32 and 512 kbps.');
            }
            $abitrate = $parsedAudio;
        }

        $rawScale = trim((string)($options['scale'] ?? ''));
        if (array_key_exists($rawScale, self::SCALE_PRESETS)) {
            $scale = self::SCALE_PRESETS[$rawScale];
        } elseif (preg_match('/^(\d{2,4}):(-1|-2|\d{2,4})$/', $rawScale, $matches) === 1) {
            $w = (int)$matches[1];
            if ($w < 64 || $w > 7680) {
                throw new InvalidArgumentException('Scale width must be between 64 and 7680 pixels.');
            }
            $w -= ($w % 2);
            if ($matches[2] === '-1' || $matches[2] === '-2') {
                $scale = $w . ':-2';
            } else {
                $h = (int)$matches[2];
                if ($h < 64 || $h > 4320) {
                    throw new InvalidArgumentException('Scale height must be between 64 and 4320 pixels.');
                }
                $h -= ($h % 2);
                $scale = $w . ':' . $h;
            }
        } else {
            throw new InvalidArgumentException('Unsupported resolution scale option.');
        }

        $defaultFaststart = self::OUTPUT_FORMATS[$format]['faststart'];
        if (array_key_exists('faststart', $options)) {
            $faststart = filter_var($options['faststart'], FILTER_VALIDATE_BOOLEAN);
        } elseif (array_key_exists('movflags', $options)) {
            $movVal = (string)$options['movflags'];
            $faststart = ($movVal === '1' || strtolower($movVal) === 'true' || str_contains($movVal, 'faststart'));
        } else {
            $faststart = $defaultFaststart;
        }

        $overwrite = false;
        if (array_key_exists('overwrite', $options)) {
            $overwrite = filter_var($options['overwrite'], FILTER_VALIDATE_BOOLEAN);
        }

        $priority = (int)($options['priority'] ?? 0);
        if (!in_array($priority, [-10, 0, 10], true)) {
            $priority = 0;
        }

        return [
            'format' => $format,
            'preset' => $preset,
            'codec' => $codec,
            'vbitrate' => $vbitrate,
            'abitrate' => $abitrate,
            'scale' => $scale,
            'faststart' => $faststart,
            'overwrite' => $overwrite,
            'priority' => $priority,
        ];
    }

    /**
     * Convert a Nextcloud user video synchronously and register output in Nextcloud Files.
     *
     * @param array<string, mixed> $rawOptions
     * @return array{
     *   source: string,
     *   output: string,
     *   name: string,
     *   fileid: int,
     *   size: int,
     *   mimetype: string,
     *   format: string
     * }
     */
    public function convert(string $userId, string $sourceRelativePath, array $rawOptions = []): array {
        $ffmpegBinary = $this->findExecutable('ffmpeg');
        if ($ffmpegBinary === null) {
            throw new RuntimeException('FFmpeg binary not found on the server.');
        }

        $sourceFile = $this->resolveSourceFile($userId, $sourceRelativePath);
        $options = $this->normalizeOptions($rawOptions);
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $targetFolder = $sourceFile->getParent();

        $workDir = $this->resolveTempDirectory();
        $token = bin2hex(random_bytes(8));
        $tmpInput = null;
        $tmpOutput = $workDir . DIRECTORY_SEPARATOR . 'nc_vconv_out_' . $token . '.' . $options['format'];

        try {
            $inputPath = $this->prepareLocalInput($sourceFile, $workDir, $token, $tmpInput);
            $command = $this->buildCommand($ffmpegBinary, $inputPath, $tmpOutput, $options);

            $this->runProcess($command);

            if (!is_file($tmpOutput) || filesize($tmpOutput) === 0) {
                throw new RuntimeException('FFmpeg completed without producing a valid output file.');
            }

            $outputName = $this->resolveDestinationFilename(
                $targetFolder,
                $sourceFile->getName(),
                $options['format'],
                $options['overwrite']
            );

            $outputFile = $this->persistToNextcloudFolder($targetFolder, $outputName, $tmpOutput);
            $relativeOut = $userFolder->getRelativePath($outputFile->getPath());

            return [
                'source' => $userFolder->getRelativePath($sourceFile->getPath()) ?? $sourceRelativePath,
                'output' => $relativeOut ?? ('/' . $outputName),
                'name' => $outputFile->getName(),
                'fileid' => (int)$outputFile->getId(),
                'size' => (int)$outputFile->getSize(),
                'mimetype' => (string)$outputFile->getMimeType(),
                'format' => $options['format'],
            ];
        } finally {
            if ($tmpInput !== null && is_file($tmpInput)) {
                @unlink($tmpInput);
            }
            if (is_file($tmpOutput)) {
                @unlink($tmpOutput);
            }
        }
    }

    /**
     * Build the exact argument vector passed directly to proc_open without a shell.
     *
     * @param array{
     *   format: string,
     *   preset: string,
     *   codec: string,
     *   vbitrate: int|null,
     *   abitrate: int,
     *   scale: string|null,
     *   faststart: bool,
     *   overwrite: bool,
     *   priority: int
     * } $options
     * @return list<string>
     */
    public function buildCommand(string $ffmpegBinary, string $inputPath, string $outputPath, array $options): array {
        $formatSpec = self::OUTPUT_FORMATS[$options['format']];
        $cmd = [];

        // Non-root users can only increase niceness (> 0).
        $priority = $options['priority'];
        if ($priority < 0 && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $priority = 0;
        }
        if ($priority !== 0) {
            $niceBin = $this->findExecutable('nice');
            if ($niceBin !== null) {
                $cmd[] = $niceBin;
                $cmd[] = '-n';
                $cmd[] = (string)$priority;
            }
        }

        $cmd[] = $ffmpegBinary;
        $cmd[] = '-hide_banner';
        $cmd[] = '-loglevel';
        $cmd[] = 'error';
        $cmd[] = '-y';
        $cmd[] = '-i';
        $cmd[] = $inputPath;

        if ($formatSpec['category'] === 'audio') {
            $cmd[] = '-vn';
            $cmd[] = '-c:a';
            $cmd[] = $formatSpec['audio_codec'];
            $cmd[] = '-b:a';
            $cmd[] = $options['abitrate'] . 'k';
            if ($options['format'] === 'm4a' && $options['faststart']) {
                $cmd[] = '-movflags';
                $cmd[] = '+faststart';
            }
            $cmd[] = $outputPath;
            return $cmd;
        }

        $videoCodec = $options['codec'] === 'auto'
            ? (string)$formatSpec['video_codec']
            : $options['codec'];

        // WebM only supports VP8/VP9/AV1 video streams; prevent incompatible H.264 selection unless explicitly copying.
        if ($options['format'] === 'webm' && !in_array($videoCodec, ['libvpx', 'libvpx-vp9', 'copy'], true)) {
            $videoCodec = 'libvpx';
        }

        $cmd[] = '-c:v';
        $cmd[] = $videoCodec;

        if ($videoCodec !== 'copy') {
            if (in_array($videoCodec, ['libx264', 'libx265'], true)) {
                $cmd[] = '-preset';
                $cmd[] = $options['preset'];
                $cmd[] = '-pix_fmt';
                $cmd[] = 'yuv420p';
            } elseif ($videoCodec === 'libvpx-vp9') {
                $cmd[] = '-deadline';
                $cmd[] = 'good';
                $cmd[] = '-cpu-used';
                $cmd[] = '2';
            }

            // Ensure even dimensions for YUV 4:2:0 encoders when scaling or transcoding odd inputs.
            if ($options['scale'] !== null) {
                $cmd[] = '-vf';
                $cmd[] = 'scale=' . $options['scale'];
            } elseif (in_array($videoCodec, ['libx264', 'libx265'], true)) {
                $cmd[] = '-vf';
                $cmd[] = 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
            }

            if ($options['vbitrate'] !== null) {
                $cmd[] = '-b:v';
                $cmd[] = $options['vbitrate'] . 'k';
            } elseif ($videoCodec === 'libvpx' || $videoCodec === 'libvpx-vp9') {
                $cmd[] = '-crf';
                $cmd[] = '30';
                $cmd[] = '-b:v';
                $cmd[] = '0';
            }
        }

        $audioCodec = $videoCodec === 'copy' && $options['codec'] === 'copy'
            ? 'copy'
            : $formatSpec['audio_codec'];
        $cmd[] = '-c:a';
        $cmd[] = $audioCodec;
        if ($audioCodec !== 'copy') {
            $cmd[] = '-b:a';
            $cmd[] = $options['abitrate'] . 'k';
        }

        if ($options['faststart'] && in_array($options['format'], ['mp4', 'm4v', 'mov'], true)) {
            $cmd[] = '-movflags';
            $cmd[] = '+faststart';
        }

        $cmd[] = $outputPath;
        return $cmd;
    }

    /**
     * Execute command array via proc_open without shell interpolation.
     *
     * @param list<string> $command
     */
    private function runProcess(array $command): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start FFmpeg process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $errorDetail = trim((string)$stderr ?: (string)$stdout);
            $this->logger->error('Video conversion failed', [
                'app' => 'video_converter',
                'exit_code' => $exitCode,
                'stderr' => mb_substr($errorDetail, 0, 1000),
            ]);
            throw new RuntimeException(
                'FFmpeg exited with status ' . $exitCode . ($errorDetail !== '' ? ' (' . mb_substr($errorDetail, 0, 400) . ')' : '')
            );
        }
    }

    private function prepareLocalInput(File $sourceFile, string $workDir, string $token, ?string &$tmpInput): string {
        $storage = $sourceFile->getStorage();
        $internalPath = $sourceFile->getInternalPath();
        $localPath = $storage->getLocalFile($internalPath);
        if (is_string($localPath) && $localPath !== '' && is_file($localPath) && is_readable($localPath)) {
            return $localPath;
        }

        $ext = strtolower(pathinfo($sourceFile->getName(), PATHINFO_EXTENSION));
        $suffix = $ext !== '' ? ('.' . $ext) : '.bin';
        $tmpInput = $workDir . DIRECTORY_SEPARATOR . 'nc_vconv_in_' . $token . $suffix;

        $readStream = $sourceFile->fopen('rb');
        if (!is_resource($readStream)) {
            throw new RuntimeException('Failed to open source stream from Nextcloud storage.');
        }

        $writeStream = fopen($tmpInput, 'wb');
        if (!is_resource($writeStream)) {
            fclose($readStream);
            throw new RuntimeException('Failed to allocate temporary input file.');
        }

        try {
            stream_copy_to_stream($readStream, $writeStream);
        } finally {
            fclose($readStream);
            fclose($writeStream);
        }

        return $tmpInput;
    }

    private function resolveDestinationFilename(
        Folder $targetFolder,
        string $sourceFilename,
        string $targetFormat,
        bool $overwrite
    ): string {
        $baseName = pathinfo($sourceFilename, PATHINFO_FILENAME);
        if ($baseName === '') {
            $baseName = 'converted';
        }

        $candidate = $baseName . '.' . $targetFormat;
        if ($overwrite && $candidate !== $sourceFilename) {
            return $candidate;
        }
        if ($overwrite && $candidate === $sourceFilename) {
            return $candidate;
        }
        if (!$targetFolder->nodeExists($candidate)) {
            return $candidate;
        }

        $suffixBase = $baseName . ' (converted)';
        $candidate = $suffixBase . '.' . $targetFormat;
        $counter = 2;
        while ($targetFolder->nodeExists($candidate)) {
            $candidate = sprintf('%s (%d).%s', $suffixBase, $counter, $targetFormat);
            $counter++;
        }

        return $candidate;
    }

    private function persistToNextcloudFolder(Folder $targetFolder, string $outputName, string $tmpOutput): File {
        $inStream = fopen($tmpOutput, 'rb');
        if (!is_resource($inStream)) {
            throw new RuntimeException('Unable to read converted temporary file.');
        }

        try {
            if ($targetFolder->nodeExists($outputName)) {
                $existing = $targetFolder->get($outputName);
                if (!($existing instanceof File)) {
                    throw new RuntimeException('A folder with the target output name already exists.');
                }
                $targetFile = $existing;
            } else {
                $targetFile = $targetFolder->newFile($outputName);
            }

            $outStream = $targetFile->fopen('wb');
            if (!is_resource($outStream)) {
                throw new RuntimeException('Unable to write converted file into Nextcloud storage.');
            }

            try {
                stream_copy_to_stream($inStream, $outStream);
            } finally {
                fclose($outStream);
            }
        } finally {
            fclose($inStream);
        }

        // Explicitly scan the file in storage so filecache metadata (size, etag, mimetype) is up to date immediately.
        $storage = $targetFile->getStorage();
        $scanner = $storage->getScanner($targetFile->getInternalPath());
        $scanner->scanFile($targetFile->getInternalPath());

        // Re-fetch node so returned size/id/mimetype reflect scanned metadata.
        $refreshed = $targetFolder->get($outputName);
        return $refreshed instanceof File ? $refreshed : $targetFile;
    }

    private function resolveTempDirectory(): string {
        $sysTmp = sys_get_temp_dir();
        $freeTmp = @disk_free_space($sysTmp);
        if (($freeTmp === false || $freeTmp < 268435456) && is_dir('/var/tmp') && is_writable('/var/tmp')) {
            return '/var/tmp';
        }
        return $sysTmp;
    }

    public function findExecutable(string $binary): ?string {
        $candidates = [
            '/usr/bin/' . $binary,
            '/usr/local/bin/' . $binary,
            '/bin/' . $binary,
            '/opt/homebrew/bin/' . $binary,
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $pathEnv = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            $full = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($full) && is_executable($full)) {
                return $full;
            }
        }

        return null;
    }
}
