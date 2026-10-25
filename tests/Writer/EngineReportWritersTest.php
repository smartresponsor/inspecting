<?php

declare(strict_types=1);

namespace App\Tests\Writer;

use App\Inspecting\ValueObject\EngineInspectionReport;
use App\Inspecting\Writer\EngineRawReportWriter;
use App\Inspecting\Writer\EngineReportWriter;
use PHPUnit\Framework\TestCase;

final class EngineReportWritersTest extends TestCase
{
    public function testNormalizedReportCreatesDirectoriesAndSerializesEvidence(): void
    {
        $root = $this->fixtureDirectory();
        try {
            $report = new EngineInspectionReport(
                target: $root,
                startedAt: new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
                finishedAt: new \DateTimeImmutable('2026-01-02T03:04:06+00:00'),
                analyzers: ['semgrep'],
                findings: [],
                metrics: ['total' => 2],
                rawReportReferences: ['raw.json'],
                analyzerFailures: ['other' => 'unavailable'],
            );
            $writer = new EngineReportWriter();
            $path = $root.'/nested/report.json';
            self::assertSame($path, $writer->write($report, $path));
            self::assertFileExists($path);
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringEndsWith("\n", $contents);
            $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($json);
            self::assertSame(['semgrep'], $json['analyzers']);
            self::assertSame(['total' => 2], $json['metrics']);
            self::assertSame(['other' => 'unavailable'], $json['analyzerFailures']);
            self::assertStringContainsString('20260102-030405.json', $writer->defaultPath($report));
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRawWriterPreservesExactContentAndSanitizesPath(): void
    {
        $root = $this->fixtureDirectory();
        try {
            $writer = new EngineRawReportWriter();
            $stamp = new \DateTimeImmutable('2026-01-02T03:04:05.123456+00:00');
            $default = $writer->defaultPath($root, 'engine:semgrep/rules', $stamp);
            self::assertStringContainsString('20260102-030405-123456-engine-semgrep-rules.json', $default);

            $path = $root.'/raw/deep/data.json';
            $payload = "{\"raw\":true}\n";
            self::assertSame($path, $writer->write($root, 'semgrep', $payload, $path, $stamp));
            self::assertSame($payload, file_get_contents($path));
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRawWriterUsesFallbackSlugForEmptyAnalyzer(): void
    {
        $writer = new EngineRawReportWriter();
        $stamp = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
        self::assertStringEndsWith('-artifact.json', $writer->defaultPath('some/path', ':', $stamp));
    }

    private function fixtureDirectory(): string
    {
        $path = sys_get_temp_dir().'/inspecting-writer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0775, true));

        return $path;
    }

    private function cleanup(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
