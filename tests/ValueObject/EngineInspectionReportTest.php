<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Inspecting\ValueObject\EngineInspectionReport;
use PHPUnit\Framework\TestCase;

final class EngineInspectionReportTest extends TestCase
{
    public function testRejectsFinishTimeEarlierThanStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finishedAt cannot precede startedAt.');

        new EngineInspectionReport(
            target: '/fixture',
            startedAt: new \DateTimeImmutable('2026-10-25T12:00:00+00:00'),
            finishedAt: new \DateTimeImmutable('2026-10-25T11:59:59+00:00'),
            analyzers: [],
            findings: [],
        );
    }

    public function testAllowsEqualTimestampsAndSerializesReport(): void
    {
        $time = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $report = new EngineInspectionReport(
            target: '/fixture',
            startedAt: $time,
            finishedAt: $time,
            analyzers: ['phpstan'],
            findings: [],
            metrics: ['errors' => 0],
        );

        $serialized = $report->jsonSerialize();
        self::assertSame('/fixture', $serialized['target']);
        self::assertSame($time->format(DATE_ATOM), $serialized['startedAt']);
        self::assertSame($serialized['startedAt'], $serialized['finishedAt']);
        self::assertSame(['phpstan'], $serialized['analyzers']);
        self::assertSame(['errors' => 0], $serialized['metrics']);
    }
}
