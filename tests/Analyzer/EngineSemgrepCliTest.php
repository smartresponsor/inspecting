<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EngineSemgrepAnalyzer;
use PHPUnit\Framework\TestCase;

final class EngineSemgrepCliTest extends TestCase
{
    public function testNormalizesFindingsAndEngineErrors(): void
    {
        $root = $this->fixture();
        try {
            $this->script($root, [
                'results' => [
                    ['check_id' => 'security.rule', 'path' => 'src\\Danger.php',
                        'start' => ['line' => 12],
                        'extra' => ['message' => 'Unsafe call', 'severity' => 'ERROR',
                            'metadata' => ['category' => 'security', 'confidence' => '1.5']]],
                    ['check_id' => 'style.rule', 'path' => 'src/Style.php',
                        'extra' => ['severity' => 'INFO', 'metadata' => ['confidence' => '-1']]],
                    ['check_id' => 'unknown.rule', 'path' => 'src/Unknown.php',
                        'extra' => ['severity' => 'UNKNOWN']],
                    ['check_id' => 'invalid', 'path' => 123, 'extra' => []],
                    null,
                ],
                'errors' => [['message' => 'Engine warning'], ['message' => 123], null],
            ], 1);
            $result = $this->analyzer($root)->inspect($root);
            self::assertSame('semgrep', $result->analyzer);
            self::assertSame(['findings' => 4, 'engineErrors' => 3], $result->metrics);
            self::assertCount(4, $result->findings);
            self::assertSame('security', $result->findings[0]->category);
            self::assertSame('high', $result->findings[0]->severity);
            self::assertSame(1.0, $result->findings[0]->confidence);
            self::assertSame('src/Danger.php', $result->findings[0]->file);
            self::assertSame(12, $result->findings[0]->line);
            self::assertSame('low', $result->findings[1]->severity);
            self::assertSame(0.0, $result->findings[1]->confidence);
            self::assertSame('medium', $result->findings[2]->severity);
            self::assertSame('semgrep.engine', $result->findings[3]->rule);
            self::assertCount(1, $result->rawReportReferences);
            self::assertFileExists($result->rawReportReferences[0]);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testIgnoresInvalidResultsAndErrorsContainers(): void
    {
        $root = $this->fixture();
        try {
            $this->script($root, ['results' => 'invalid', 'errors' => 'invalid']);
            $result = $this->analyzer($root)->inspect($root);
            self::assertSame([], $result->findings);
            self::assertSame(['findings' => 0, 'engineErrors' => 0], $result->metrics);

            $this->script($root, ['results' => [
                ['check_id' => 'default.rule', 'path' => 'src/Foo.php',
                    'start' => 'invalid', 'extra' => ['severity' => 37, 'metadata' => 'invalid', 'message' => 123]],
            ]]);
            $finding = $this->analyzer($root)->inspect($root)->findings[0];
            self::assertSame('pattern', $finding->category);
            self::assertSame(0.8, $finding->confidence);
            self::assertSame('medium', $finding->severity);
            self::assertSame('Semgrep finding', $finding->message);
            self::assertNull($finding->line);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRejectsFailureExitCode(): void
    {
        $root = $this->fixture();
        try {
            $this->script($root, ['errors' => []], 4);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Semgrep failed with exit code 4');
            $this->analyzer($root)->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRejectsMalformedJson(): void
    {
        $root = $this->fixture();
        try {
            self::assertNotFalse(file_put_contents($root.'/scan', "<?php echo '{invalid-json';"));
            $this->expectException(\JsonException::class);
            $this->analyzer($root)->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    private function analyzer(string $root): EngineSemgrepAnalyzer
    {
        return new EngineSemgrepAnalyzer(binary: PHP_BINARY, config: $root.'/rules.yml');
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/inspecting-semgrep-cli-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root.'/src', 0775, true));
        self::assertNotFalse(file_put_contents($root.'/rules.yml', 'rules: []'));
        return $root;
    }

    /** @param array<string, mixed> $json */
    private function script(string $root, array $json, int $exitCode = 0): void
    {
        $output = json_encode($json, JSON_THROW_ON_ERROR);
        self::assertNotFalse(file_put_contents(
            $root.'/scan',
            '<?php echo '.var_export($output, true).'; exit('.$exitCode.');',
        ));
    }

    private function cleanup(string $root): void
    {
        unlink($root.'/scan');
        unlink($root.'/rules.yml');
        rmdir($root.'/src');
        rmdir($root);
    }
}
