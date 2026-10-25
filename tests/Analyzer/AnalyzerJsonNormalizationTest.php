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
