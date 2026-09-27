<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Inspection\Finding;
use App\Inspection\InspectionReport;
use App\Inspection\ReportWriter;
use PHPUnit\Framework\TestCase;

final class ReportWriterTest extends TestCase
{
    public function testItWritesNormalizedJsonReport(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inspecting-report-' . bin2hex(random_bytes(4)) . '.json';
        $report = new InspectionReport(
            target: '.',
            startedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            finishedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            analyzers: ['test'],
            findings: [new Finding('test', 'rule', 'design', 'medium', 0.8, 'Example')],
        );

        try {
            $writtenPath = (new ReportWriter())->write($report, $path);
            $json = file_get_contents($writtenPath);

            self::assertSame($path, $writtenPath);
            self::assertIsString($json);
            self::assertStringContainsString('"findingCount": 1', $json);
            self::assertStringContainsString('"test"', $json);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
