<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Analyzer\AnalyzerInterface;
use App\Analyzer\AnalyzerResult;
use App\Inspection\Inspector;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testItIsolatesAnalyzerFailures(): void
    {
        $failing = new class implements AnalyzerInterface {
            public function name(): string
            {
                return 'failing';
            }

            public function inspect(string $target): AnalyzerResult
            {
                throw new \RuntimeException('synthetic analyzer failure');
            }
        };
        $healthy = new class implements AnalyzerInterface {
            public function name(): string
            {
                return 'healthy';
            }

            public function inspect(string $target): AnalyzerResult
            {
                return new AnalyzerResult('healthy', [], ['value' => 7]);
            }
        };

        $report = (new Inspector([$failing, $healthy]))->inspect(__DIR__);

        self::assertSame(['healthy'], $report->analyzers);
        self::assertSame(['healthy.value' => 7], $report->metrics);
        self::assertSame(['failing' => 'synthetic analyzer failure'], $report->analyzerFailures);
    }

    public function testItCanSelectAnalyzersExplicitly(): void
    {
        $first = new class implements AnalyzerInterface {
            public function name(): string
            {
                return 'first';
            }

            public function inspect(string $target): AnalyzerResult
            {
                return new AnalyzerResult('first', [], ['value' => 1]);
            }
        };
        $second = new class implements AnalyzerInterface {
            public function name(): string
            {
                return 'second';
            }

            public function inspect(string $target): AnalyzerResult
            {
                return new AnalyzerResult('second', [], ['value' => 2]);
            }
        };

        $report = (new Inspector([$first, $second]))->inspect(__DIR__, ['second']);

        self::assertSame(['second'], $report->analyzers);
        self::assertSame(['second.value' => 2], $report->metrics);
    }
}
