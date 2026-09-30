<?php

declare(strict_types=1);

namespace App\Inspecting\Exception;

/**
 * Signals that an analyzer cannot execute because its required binary, configuration, or runtime capability is unavailable for the target.
 */
final class EngineAnalyzerUnavailable extends \RuntimeException {}
