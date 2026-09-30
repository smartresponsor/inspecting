<?php

declare(strict_types=1);

namespace App\Inspecting\ValueObject;

/**
 * Aggregates normalized findings into stable counts by severity, category, source, and autofixability for report consumers.
 */
final readonly class EngineInspectionSummary implements \JsonSerializable
{
    /**
     * @param array<string, int> $bySeverity
     * @param array<string, int> $byCategory
     * @param array<string, int> $bySource
     */
    public function __construct(
        public int $findingCount,
        public int $autofixableCount,
        public array $bySeverity,
        public array $byCategory,
        public array $bySource,
    ) {}

    /**
 * Builds an aggregate inspection summary by counting findings across severity, category, source, and autofixability dimensions.
 *
     * @param list<EngineFinding> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $bySeverity = [];
        $byCategory = [];
        $bySource = [];
        $autofixableCount = 0;

        foreach ($findings as $finding) {
            self::increment($bySeverity, $finding->severity);
            self::increment($byCategory, $finding->category);
            self::increment($bySource, $finding->source);

            if ($finding->autofixable) {
                ++$autofixableCount;
            }
        }

        ksort($bySeverity);
        ksort($byCategory);
        ksort($bySource);

        return new self(
            findingCount: count($findings),
            autofixableCount: $autofixableCount,
            bySeverity: $bySeverity,
            byCategory: $byCategory,
            bySource: $bySource,
        );
    }

    /**
 * Serializes this inspection value into the stable array shape consumed by JSON reports and downstream automation.
 *
     * @return array{
     *     findingCount: int,
     *     autofixableCount: int,
     *     bySeverity: array<string, int>,
     *     byCategory: array<string, int>,
     *     bySource: array<string, int>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'findingCount' => $this->findingCount,
            'autofixableCount' => $this->autofixableCount,
            'bySeverity' => $this->bySeverity,
            'byCategory' => $this->byCategory,
            'bySource' => $this->bySource,
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private static function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
}
