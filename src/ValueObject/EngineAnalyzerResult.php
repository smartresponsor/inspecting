<?php

declare(strict_types=1);

namespace App\Inspecting\ValueObject;

use App\Inspecting\ValueObject\EngineFinding;

/**
 * Carries one analyzer's normalized findings, metrics, and raw report references across the Inspecting orchestration boundary.
 */
final readonly class EngineAnalyzerResult
{
    /**
     * @param list<EngineFinding> $findings
     * @param array<string, int|float|string|bool|null> $metrics
     * @param list<string> $rawReportReferences
     */
    public function __construct(
        public string $analyzer,
        public array $findings,
        public array $metrics = [],
        public array $rawReportReferences = [],
    ) {}
}
