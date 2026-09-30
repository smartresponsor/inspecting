<?php

declare(strict_types=1);

namespace App\Inspecting\ValueObject;

/**
 * Represents one normalized inspection finding with source, rule, severity, confidence, location, evidence, and remediation metadata.
 */
final readonly class EngineFinding implements \JsonSerializable
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $evidence
     */
    public function __construct(
        public string $source,
        public string $rule,
        public string $category,
        public string $severity,
        public float $confidence,
        public string $message,
        public array $evidence = [],
        public ?string $file = null,
        public ?int $line = null,
        public ?int $endLine = null,
        public ?string $remediation = null,
        public bool $autofixable = false,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence must be between 0.0 and 1.0.');
        }
    }

    /**
 * Serializes this inspection value into the stable array shape consumed by JSON reports and downstream automation.
 *
     * @return array{
     *     source: string,
     *     rule: string,
     *     category: string,
     *     severity: string,
     *     confidence: float,
     *     message: string,
     *     evidence: array<string, scalar|array<array-key, scalar|null>|null>,
     *     file: ?string,
     *     line: ?int,
     *     endLine: ?int,
     *     remediation: ?string,
     *     autofixable: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'rule' => $this->rule,
            'category' => $this->category,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'file' => $this->file,
            'line' => $this->line,
            'endLine' => $this->endLine,
            'remediation' => $this->remediation,
            'autofixable' => $this->autofixable,
        ];
    }
}
