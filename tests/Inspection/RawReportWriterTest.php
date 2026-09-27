<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Inspection\RawReportWriter;
use PHPUnit\Framework\TestCase;

final class RawReportWriterTest extends TestCase
{
    public function testItWritesRawAnalyzerPayloadWithoutTransformingIt(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inspecting-raw-' . bin2hex(random_bytes(4)) . '.json';
        $payload = "{\"totals\":{\"errors\":0}}\n";

        try {
            $writtenPath = (new RawReportWriter())->write('.', 'phpstan', $payload, $path);

            self::assertSame($path, $writtenPath);
            self::assertSame($payload, file_get_contents($writtenPath));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testDefaultPathSeparatesRawArtifactsByTargetAndAnalyzer(): void
    {
        $writer = new RawReportWriter();
        $path = $writer->defaultPath(
            'C:\\workspace\\Example',
            'phpstan',
            new \DateTimeImmutable('2026-09-27T02:15:16.123456+00:00'),
        );

        self::assertStringContainsString('.inspecting' . DIRECTORY_SEPARATOR . 'raw', $path);
        self::assertStringContainsString('C--workspace-Example', $path);
        self::assertStringEndsWith('20260927-021516-123456-phpstan.json', $path);
    }
}
