<?php

declare(strict_types=1);

namespace App\Inspecting\ValueObject;

/**
 * Represents the complete normalized result of one inspection run, including timing, analyzers, findings, metrics, and failures.
 */
final readonly class EngineInspectionReport implements \JsonSerializable
{
    /**
     * @param list<string> $analyzers
     * @param list<EngineFinding> $findings
     * @param array<string, int|float|string|bool|null> $metrics
     * @param list<string> $rawReportReferences
     * @param array<string, string> $analyzerFailures
     */
    public function __construct(
        public string $target,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        public array $analyzers,
        public array $findings,
        public array $metrics = [],
        public array $rawReportReferences = [],
        public array $analyzerFailures = [],
    ) {
        if ($finishedAt < $startedAt) {
            throw new \InvalidArgumentException('finishedAt cannot precede startedAt.');
        }
    }

    /**
 * Serializes this inspection value into the stable array shape consumed by JSON reports and downstream automation.
 *
     * @return array{
     *     target: string,
     *     startedAt: string,
     *     finishedAt: string,
     *     analyzers: list<string>,
     *     findings: list<EngineFinding>,
     *     metrics: array<string, int|float|string|bool|null>,
     *     summary: EngineInspectionSummary,
     *     rawReportReferences: list<string>,
     *     analyzerFailures: array<string, string>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'target' => $this->target,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(DATE_ATOM),
            'analyzers' => $this->analyzers,
            'findings' => $this->findings,
            'metrics' => $this->metrics,
            'summary' => EngineInspectionSummary::fromFindings($this->findings),
            'rawReportReferences' => $this->rawReportReferences,
            'analyzerFailures' => $this->analyzerFailures,
        ];
    }
}
