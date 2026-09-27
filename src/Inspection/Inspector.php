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

    /**
     * @param list<string> $selectedAnalyzers
     */
    public function inspect(string $target, array $selectedAnalyzers = []): InspectionReport
    {
        $startedAt = new \DateTimeImmutable();
        $names = [];
        $findings = [];
        $metrics = [];
        $rawReportReferences = [];
        $analyzerFailures = [];

        foreach ($this->analyzers as $analyzer) {
            if ([] !== $selectedAnalyzers && !in_array($analyzer->name(), $selectedAnalyzers, true)) {
                continue;
            }

            try {
                $result = $analyzer->inspect($target);
            } catch (AnalyzerUnavailable) {
                continue;
            } catch (\Throwable $failure) {
                $analyzerFailures[$analyzer->name()] = $failure->getMessage();
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
            analyzerFailures: $analyzerFailures,
        );
    }
}
