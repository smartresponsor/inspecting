<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EnginePhpStanAnalyzer;
use App\Inspecting\Analyzer\EngineRectorAnalyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerJsonNormalizationTest extends TestCase
{
    public function testPhpStanNormalizesFileAndGlobalDiagnostics(): void
    {
        $root = $this->fixture();
        try {
            $this->binary($root, 'phpstan', [
                'files' => ['src/Thing.php' => ['messages' => [
                    ['message' => 'Unknown method', 'identifier' => 'method.notFound', 'line' => 17, 'ignorable' => true],
                    ['message' => 'Missing return type', 'line' => 24],
                ]]],
                'errors' => ['Configuration warning'],
                'totals' => ['errors' => 3, 'file_errors' => 2],
            ]);
            $result = (new EnginePhpStanAnalyzer())->inspect($root);
            self::assertSame('phpstan', $result->analyzer);
            self::assertSame(['errors' => 3, 'fileErrors' => 2], $result->metrics);
            self::assertCount(3, $result->findings);
            self::assertSame('method.notFound', $result->findings[0]->rule);
            self::assertSame(17, $result->findings[0]->line);
            self::assertSame(['ignorable' => true], $result->findings[0]->evidence);
            self::assertSame('phpstan.issue', $result->findings[1]->rule);
            self::assertSame('phpstan.global', $result->findings[2]->rule);
            self::assertCount(1, $result->rawReportReferences);
            self::assertFileExists($result->rawReportReferences[0]);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testPhpStanRejectsMalformedJson(): void
    {
        $root = $this->fixture();
        try {
            $this->binary($root, 'phpstan', null);
            $this->expectException(\JsonException::class);
            (new EnginePhpStanAnalyzer())->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRectorNormalizesSuggestedChangesAndTotals(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root.'/rector.php', '<?php return [];');
            $this->binary($root, 'rector', [
                'file_diffs' => [
                    ['file' => 'src/Thing.php', 'applied_rectors' => ['RemoveDeadCodeRector', 'TypedPropertyRector']],
                    ['file' => 'src/Other.php', 'applied_rectors' => [null]],
                ],
                'totals' => ['changed_files' => 1, 'errors' => 0],
            ], 2);
            $result = (new EngineRectorAnalyzer())->inspect($root);
            self::assertSame(['changedFiles' => 1, 'errors' => 0], $result->metrics);
            self::assertCount(2, $result->findings);
            self::assertSame('RemoveDeadCodeRector', $result->findings[0]->rule);
            self::assertTrue($result->findings[0]->autofixable);
            self::assertSame('modernization', $result->findings[1]->category);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testPhpStanSkipsInvalidDiagnosticShapesAndFallsBackToCounts(): void
    {
        $root = $this->fixture();
        try {
            $this->binary($root, 'phpstan', [
                'files' => [
                    'src/Bad.php' => ['messages' => 'not-an-array'],
                    'src/Valid.php' => ['messages' => [
                        null,
                        ['identifier' => 99, 'message' => 100, 'line' => '7'],
                    ]],
                    123 => ['messages' => [['message' => 'ignored']]],
                    'src/Unexpected.php' => 23,
                ],
                'errors' => ['real error', 42],
                'totals' => ['errors' => 'invalid'],
            ]);
            $result = (new EnginePhpStanAnalyzer())->inspect($root);
            self::assertCount(2, $result->findings);
            self::assertSame('phpstan.issue', $result->findings[0]->rule);
            self::assertSame('PHPStan finding', $result->findings[0]->message);
            self::assertNull($result->findings[0]->line);
            self::assertSame(['errors' => 0, 'fileErrors' => 2], $result->metrics);

            $this->binary($root, 'phpstan', ['files' => 'invalid', 'errors' => 'invalid']);
            self::assertSame([], (new EnginePhpStanAnalyzer())->inspect($root)->findings);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRectorSkipsMalformedDiffsAndUsesDefaultMetrics(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root.'/rector.php', '<?php return [];');
            $this->binary($root, 'rector', [
                'file_diffs' => [
                    null,
                    ['file' => 123, 'applied_rectors' => ['Ignored']],
                    ['file' => 'src/Thing.php', 'applied_rectors' => 'invalid'],
                    ['file' => 'src/Thing.php', 'applied_rectors' => [null, 'ValidRector']],
                ],
                'totals' => ['changed_files' => 'invalid', 'errors' => null],
            ]);
            $result = (new EngineRectorAnalyzer())->inspect($root);
            self::assertSame(['changedFiles' => 0, 'errors' => 0], $result->metrics);
            self::assertCount(1, $result->findings);
            self::assertSame('ValidRector', $result->findings[0]->rule);

            $this->binary($root, 'rector', ['file_diffs' => 'invalid', 'totals' => null]);
            self::assertSame([], (new EngineRectorAnalyzer())->inspect($root)->findings);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testPhpStanRejectsUnexpectedExitStatus(): void
    {
        $root = $this->fixture();
        try {
            $this->binary($root, 'phpstan', ['files' => []], 3);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PHPStan failed with exit code 3');
            (new EnginePhpStanAnalyzer())->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRectorRejectsUnexpectedExitStatus(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root.'/rector.php', '<?php return [];');
            $this->binary($root, 'rector', ['file_diffs' => []], 3);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Rector failed with exit code 3');
            (new EngineRectorAnalyzer())->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/inspecting-json-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root.'/vendor/bin', 0775, true));
        return $root;
    }

    /** @param array<string, mixed>|null $payload */
    private function binary(string $root, string $name, ?array $payload, int $exitCode = 0): void
    {
        $output = $payload === null ? '{invalid json' : json_encode($payload, JSON_THROW_ON_ERROR);
        $script = '<?php echo '.var_export($output, true).'; exit('.$exitCode.');';
        self::assertNotFalse(file_put_contents($root.'/vendor/bin/'.$name, $script));
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
