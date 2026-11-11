<?php

declare(strict_types=1);

namespace OCA\Video_Converter\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Video_Converter\Service\ConversionService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConversionServiceTest extends TestCase {
    private ConversionService $service;

    protected function setUp(): void {
        parent::setUp();
        $rootFolder = $this->createMock(IRootFolder::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->service = new ConversionService($rootFolder, $logger);
    }

    public function testNormalizeRelativePathRejectsTraversal(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeRelativePath('/Videos/../../etc/passwd');
    }

    public function testNormalizeRelativePathKeepsSpecialFilenameCharactersLiteral(): void {
        $clean = $this->service->normalizeRelativePath('Videos/clip $(touch pwned); test.vob');
        $this->assertSame('/Videos/clip $(touch pwned); test.vob', $clean);
    }

    public function testBuildCommandForMp3AudioExtraction(): void {
        $options = $this->service->normalizeOptions([
            'format' => 'mp3',
            'abitrate' => '192',
        ]);
        $cmd = $this->service->buildCommand('/usr/bin/ffmpeg', '/tmp/in.mp4', '/tmp/out.mp3', $options);

        $this->assertContains('-vn', $cmd);
        $this->assertContains('libmp3lame', $cmd);
        $this->assertContains('192k', $cmd);
        $this->assertSame('/tmp/out.mp3', $cmd[array_key_last($cmd)]);
    }
}
