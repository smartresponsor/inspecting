<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EngineNormativeRepositoryAnalyzer;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use PHPUnit\Framework\TestCase;

final class NormativeRepositoryAnalyzerTest extends TestCase
{
    public function testMissingNormativeDirectoryIsUnavailable(): void
    {
        $this->expectException(EngineAnalyzerUnavailable::class);
        (new EngineNormativeRepositoryAnalyzer())->inspect(__DIR__);
    }

    public function testEmptyNormativeDirectoryIsUnavailable(): void
    {
        $target = sys_get_temp_dir() . '/inspecting-normative-' . bin2hex(random_bytes(8));
        mkdir($target . '/.canonization', 0777, true);
        try {
            $this->expectException(EngineAnalyzerUnavailable::class);
            (new EngineNormativeRepositoryAnalyzer())->inspect($target);
        } finally {
            rmdir($target . '/.canonization');
            rmdir($target);
        }
    }

    public function testCountsDocumentsAndFindsEmptyDocument(): void
    {
        $target = sys_get_temp_dir() . '/inspecting-normative-' . bin2hex(random_bytes(8));
        mkdir($target . '/.canonization', 0777, true);
        file_put_contents($target . '/.canonization/Canon001.md', '# Canon001');
        file_put_contents($target . '/.canonization/Canon002.md', '   ');
        try {
            $result = (new EngineNormativeRepositoryAnalyzer())->inspect($target);
            self::assertSame('normative-repository', $result->analyzer);
            self::assertSame(2, $result->metrics['documents']);
            self::assertCount(1, $result->findings);
            self::assertSame('normative.empty-document', $result->findings[0]->rule);
        } finally {
            unlink($target . '/.canonization/Canon001.md');
            unlink($target . '/.canonization/Canon002.md');
            rmdir($target . '/.canonization');
            rmdir($target);
        }
    }
}
