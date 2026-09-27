<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Inspection\Finding;
use App\Inspection\InspectionSummary;
use PHPUnit\Framework\TestCase;

final class InspectionSummaryTest extends TestCase
{
    public function testItAggregatesFindingsForConsumers(): void
    {
        $summary = InspectionSummary::fromFindings([
            new Finding('phpstan', 'a', 'correctness', 'high', 1.0, 'A'),
            new Finding('php-structure', 'b', 'design', 'medium', 0.8, 'B'),
            new Finding('rector', 'c', 'modernization', 'medium', 1.0, 'C', autofixable: true),
        ]);

        self::assertSame(3, $summary->findingCount);
        self::assertSame(1, $summary->autofixableCount);
        self::assertSame(['high' => 1, 'medium' => 2], $summary->bySeverity);
        self::assertSame([
            'correctness' => 1,
            'design' => 1,
            'modernization' => 1,
        ], $summary->byCategory);
        self::assertSame([
            'php-structure' => 1,
            'phpstan' => 1,
            'rector' => 1,
        ], $summary->bySource);
    }
}
