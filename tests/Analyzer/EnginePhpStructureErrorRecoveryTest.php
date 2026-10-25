<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EnginePhpStructureAnalyzer;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use PHPUnit\Framework\TestCase;

final class EnginePhpStructureErrorRecoveryTest extends TestCase
{
    public function testInvalidPhpProducesFindingWithoutStoppingOtherFiles(): void
    {
        $root = $this->fixture();
        try {
            self::assertNotFalse(file_put_contents($root.'/src/Broken.php', '<?php class Broken { public function run( }'));
            self::assertNotFalse(file_put_contents($root.'/src/Valid.php', '<?php namespace Example; interface Contract { public function run(): void; } class Valid implements Contract { public function run(): void {} }'));
            self::assertNotFalse(file_put_contents($root.'/src/ignored.txt', 'not PHP'));
            $result = (new EnginePhpStructureAnalyzer())->inspect($root);
            self::assertSame('php-structure', $result->analyzer);
            self::assertSame(2, $result->metrics['files']);
            self::assertSame(1, $result->metrics['classes']);
            self::assertSame(1, $result->metrics['interfaces']);
            self::assertSame(1, $result->metrics['methods']);
            self::assertSame('php.parse-error', $result->findings[0]->rule);
            self::assertSame('src/Broken.php', $result->findings[0]->file);
            self::assertSame('critical', $result->findings[0]->severity);
            self::assertCount(1, $result->rawReportReferences);
            self::assertFileExists($result->rawReportReferences[0]);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testEmptySourceAndMissingSource(): void
    {
        $root = $this->fixture();
        try {
            $empty = (new EnginePhpStructureAnalyzer())->inspect($root);
            self::assertSame(0, $empty->metrics['files']);
            self::assertSame(0, $empty->metrics['classes']);
            rmdir($root.'/src');
            $this->expectException(EngineAnalyzerUnavailable::class);
            (new EnginePhpStructureAnalyzer())->inspect($root);
        } finally {
            $this->cleanup($root);
        }
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/inspecting-parse-recovery-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root.'/src', 0775, true));
        return $root;
    }

    private function cleanup(string $root): void
    {
        if (is_dir($root.'/src')) {
            foreach (glob($root.'/src/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root.'/src');
        }
        rmdir($root);
    }
}
