<?php

declare(strict_types=1);

namespace App\Inspecting\Contract;

use App\Inspecting\ValueObject\EngineAnalyzerResult;

/**
 * Defines the contract implemented by analyzers that expose a stable identifier and inspect repositories into normalized evidence.
 */
interface EngineAnalyzerInterface
{
    /**
 * Returns the stable analyzer identifier used for selection, metric namespacing, failure reporting, and normalized report output.
 */
    public function name(): string;

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 */
    public function inspect(string $target): EngineAnalyzerResult;
}
