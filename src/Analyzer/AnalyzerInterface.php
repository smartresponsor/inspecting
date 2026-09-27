<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\InspectionReport;

interface AnalyzerInterface
{
    public function name(): string;

    public function inspect(string $target): InspectionReport;
}
