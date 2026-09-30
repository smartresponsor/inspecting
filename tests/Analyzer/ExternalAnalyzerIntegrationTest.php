<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EnginePhpStanAnalyzer;
use App\Inspecting\Analyzer\EngineRectorAnalyzer;
use App\Inspecting\Analyzer\EngineSemgrepAnalyzer;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use PHPUnit\Framework\TestCase;

final class ExternalAnalyzerIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inspecting-adapter-' . bin2hex(random_bytes(4));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', 0775, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'src', 0775, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'rector.php', "<?php\nreturn static function (): void {};\n");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'semgrep.yml', "rules: []\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPhpStanAdapterNormalizesFileAndGlobalFindings(): void
    {
        $this->writePhpOutput('vendor/bin/phpstan', [
            'totals' => ['errors' => 2, 'file_errors' => 1],
            'files' => [
                'src/Example.php' => [
                    'messages' => [[
                        'message' => 'Synthetic PHPStan problem',
                        'line' => 7,
                        'ignorable' => true,
                        'identifier' => 'example.rule',
                    ]],
                ],
            ],
            'errors' => ['Synthetic global problem'],
        ]);

        $result = (new EnginePhpStanAnalyzer())->inspect($this->root);

        self::assertSame('phpstan', $result->analyzer);
        self::assertSame(['errors' => 2, 'fileErrors' => 1], $result->metrics);
        self::assertCount(2, $result->findings);
        self::assertSame('example.rule', $result->findings[0]->rule);
        self::assertSame('phpstan.global', $result->findings[1]->rule);
    }

    public function testPhpStanAdapterReportsMissingExecutable(): void
    {
        $this->expectException(EngineAnalyzerUnavailable::class);

        (new EnginePhpStanAnalyzer())->inspect($this->root);
    }

    public function testRectorAdapterNormalizesAppliedRules(): void
    {
        $this->writePhpOutput('vendor/bin/rector', [
            'file_diffs' => [[
                'file' => 'src/Example.php',
                'applied_rectors' => ['Rector\\Rule\\SyntheticRule'],
            ]],
            'totals' => ['changed_files' => 1, 'errors' => 0],
        ], 2);

        $result = (new EngineRectorAnalyzer())->inspect($this->root);

        self::assertSame('rector', $result->analyzer);
        self::assertSame(['changedFiles' => 1, 'errors' => 0], $result->metrics);
        self::assertCount(1, $result->findings);
        self::assertSame('Rector\\Rule\\SyntheticRule', $result->findings[0]->rule);
        self::assertTrue($result->findings[0]->autofixable);
    }

    public function testRectorAdapterReportsMissingExecutable(): void
    {
        $this->expectException(EngineAnalyzerUnavailable::class);

        (new EngineRectorAnalyzer())->inspect($this->root);
    }

    public function testSemgrepAdapterNormalizesResultsAndEngineErrors(): void
    {
        $this->writePhpOutput('scan', [
            'results' => [[
                'check_id' => 'synthetic.semgrep.rule',
                'path' => 'src\\Example.php',
                'start' => ['line' => 11],
                'extra' => [
                    'message' => 'Synthetic Semgrep problem',
                    'severity' => 'ERROR',
                    'metadata' => ['category' => 'security', 'confidence' => 0.95],
                ],
            ]],
            'errors' => [['message' => 'Synthetic engine warning']],
        ]);

        $result = (new EngineSemgrepAnalyzer(
            binary: PHP_BINARY,
            config: $this->root . DIRECTORY_SEPARATOR . 'semgrep.yml',
        ))->inspect($this->root);

        self::assertSame('semgrep', $result->analyzer);
        self::assertSame(['findings' => 2, 'engineErrors' => 1], $result->metrics);
        self::assertSame('synthetic.semgrep.rule', $result->findings[0]->rule);
        self::assertSame('high', $result->findings[0]->severity);
        self::assertSame('src/Example.php', $result->findings[0]->file);
        self::assertSame('semgrep.engine', $result->findings[1]->rule);
    }

    public function testSemgrepAdapterReportsMissingConfiguration(): void
    {
        unlink($this->root . DIRECTORY_SEPARATOR . 'semgrep.yml');
        $this->expectException(EngineAnalyzerUnavailable::class);

        (new EngineSemgrepAnalyzer(
            binary: PHP_BINARY,
            configResolver: new \App\Inspecting\Resolver\EngineSemgrepConfigResolver(
                $this->root . DIRECTORY_SEPARATOR . 'missing-rules',
            ),
        ))->inspect($this->root);
    }

    /** @param array<string, mixed> $payload */
    private function writePhpOutput(string $relativePath, array $payload, int $exitCode = 0): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents(
            $path,
            sprintf("<?php\necho %s;\nexit(%d);\n", var_export($json, true), $exitCode),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
