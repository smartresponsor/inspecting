<?php

declare(strict_types=1);

namespace App\Inspecting\Inspector;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\ValueObject\EngineInspectionReport;

/**
 * Coordinates selected analyzers, isolates analyzer failures, and aggregates normalized findings, metrics, and raw evidence into one report.
 */
final readonly class EngineInspector
{
    /**
     * @param list<EngineAnalyzerInterface> $analyzers
     */
    public function __construct(private array $analyzers) {}

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 *
     * @param list<string> $selectedAnalyzers
     */
    public function inspect(string $target, array $selectedAnalyzers = []): EngineInspectionReport
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
            } catch (EngineAnalyzerUnavailable) {
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

        return new EngineInspectionReport(
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
