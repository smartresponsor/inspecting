<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EnginePhpStanAnalyzer;
use App\Inspecting\Analyzer\EngineRectorAnalyzer;
use App\Inspecting\Analyzer\EngineSemgrepAnalyzer;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\Resolver\EngineSemgrepConfigResolver;
use PHPUnit\Framework\TestCase;

final class AnalyzerUnavailableContractsTest extends TestCase
{
    public function testAnalyzersRejectNonexistentTarget(): void
    {
        $target = sys_get_temp_dir().'/inspecting-missing-'.bin2hex(random_bytes(8));
        foreach ([new EnginePhpStanAnalyzer(), new EngineRectorAnalyzer(), new EngineSemgrepAnalyzer()] as $analyzer) {
            try {
                $analyzer->inspect($target);
                self::fail($analyzer->name().' must reject missing target');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('does not exist', $exception->getMessage());
            }
        }
    }

    public function testAnalyzersReportUnavailableDependencies(): void
    {
        $target = $this->fixtureDirectory();
        try {
            foreach ([new EnginePhpStanAnalyzer(), new EngineRectorAnalyzer()] as $analyzer) {
                try {
                    $analyzer->inspect($target);
                    self::fail($analyzer->name().' must report unavailable tooling');
                } catch (EngineAnalyzerUnavailable $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
            $semgrep = new EngineSemgrepAnalyzer(configResolver: new EngineSemgrepConfigResolver($target.'/missing-rules'));
            try {
                $semgrep->inspect($target);
                self::fail('Semgrep must reject missing configuration');
            } catch (EngineAnalyzerUnavailable $exception) {
                self::assertStringContainsString('not configured', $exception->getMessage());
            }

            file_put_contents($target.'/rules.yml', 'rules: []');
            $semgrep = new EngineSemgrepAnalyzer(config: $target.'/rules.yml');
            try {
                $semgrep->inspect($target);
                self::fail('Semgrep must reject missing source directory');
            } catch (EngineAnalyzerUnavailable $exception) {
                self::assertStringContainsString('No src directory', $exception->getMessage());
            }
        } finally {
            unlink($target.'/rules.yml');
            rmdir($target);
        }
    }

    private function fixtureDirectory(): string
    {
        $target = sys_get_temp_dir().'/inspecting-analyzer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($target, 0775, true));

        return $target;
    }
}
