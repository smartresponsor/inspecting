<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Analyzer\AnalyzerInterface;
use App\Analyzer\AnalyzerResult;
use App\Inspection\Inspector;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
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
