<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\Inspector\EngineInspector;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testItIsolatesAnalyzerFailures(): void
    {
        $failing = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'failing';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                throw new \RuntimeException('synthetic analyzer failure');
            }
        };
        $healthy = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'healthy';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                return new EngineAnalyzerResult('healthy', [], ['value' => 7]);
            }
        };

        $report = (new EngineInspector([$failing, $healthy]))->inspect(__DIR__);

        self::assertSame(['healthy'], $report->analyzers);
        self::assertSame(['healthy.value' => 7], $report->metrics);
        self::assertSame(['failing' => 'synthetic analyzer failure'], $report->analyzerFailures);
    }

    public function testUnavailableAnalyzerIsSkippedWithoutFailure(): void
    {
        $unavailable = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'unavailable';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                throw new \App\Inspecting\Exception\EngineAnalyzerUnavailable('Tool not installed');
            }
        };
        $healthy = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'healthy';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                return new EngineAnalyzerResult('healthy', [], ['value' => 1]);
            }
        };

        $report = (new EngineInspector([$unavailable, $healthy]))->inspect(__DIR__);
        self::assertSame(['healthy'], $report->analyzers);
        self::assertSame(['healthy.value' => 1], $report->metrics);
        self::assertSame([], $report->analyzerFailures);
    }

    public function testItCanSelectAnalyzersExplicitly(): void
    {
        $first = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'first';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                return new EngineAnalyzerResult('first', [], ['value' => 1]);
            }
        };
        $second = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'second';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                return new EngineAnalyzerResult('second', [], ['value' => 2]);
            }
        };

        $report = (new EngineInspector([$first, $second]))->inspect(__DIR__, ['second']);

        self::assertSame(['second'], $report->analyzers);
        self::assertSame(['second.value' => 2], $report->metrics);
    }
}
