<?php

declare(strict_types=1);

namespace App\Inspection;

use App\Analyzer\AnalyzerInterface;
use App\Analyzer\AnalyzerUnavailable;

final readonly class Inspector
{
    /**
     * @param list<AnalyzerInterface> $analyzers
     */
    public function __construct(private array $analyzers) {}

    public function inspect(string $target): InspectionReport
    {
        $startedAt = new \DateTimeImmutable();
        $names = [];
        $findings = [];
        $metrics = [];
        $rawReportReferences = [];

        foreach ($this->analyzers as $analyzer) {
            try {
                $result = $analyzer->inspect($target);
            } catch (AnalyzerUnavailable) {
                continue;
            }

            $names[] = $result->analyzer;
            array_push($findings, ...$result->findings);

            foreach ($result->metrics as $name => $value) {
                $metrics[$result->analyzer . '.' . $name] = $value;
            }

            array_push($rawReportReferences, ...$result->rawReportReferences);
        }

        return new InspectionReport(
            target: $target,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            analyzers: $names,
            findings: $findings,
            metrics: $metrics,
            rawReportReferences: $rawReportReferences,
        );
    }
}
