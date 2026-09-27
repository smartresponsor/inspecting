<?php

declare(strict_types=1);

namespace App\Inspection;

final readonly class InspectionReport implements \JsonSerializable
{
    /**
     * @param list<string> $analyzers
     * @param list<Finding> $findings
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
     * @return array{
     *     target: string,
     *     startedAt: string,
     *     finishedAt: string,
     *     analyzers: list<string>,
     *     findings: list<Finding>,
     *     metrics: array<string, int|float|string|bool|null>,
     *     summary: InspectionSummary,
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
            'summary' => InspectionSummary::fromFindings($this->findings),
            'rawReportReferences' => $this->rawReportReferences,
            'analyzerFailures' => $this->analyzerFailures,
        ];
    }
}
