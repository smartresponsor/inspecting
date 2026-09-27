<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\Finding;

final readonly class AnalyzerResult
{
    /**
     * @param list<Finding> $findings
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
