<?php

declare(strict_types=1);

namespace App\Tests\Inspection;

use App\Inspection\Finding;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testItSerializesNormalizedFinding(): void
    {
        $finding = new Finding(
            source: 'phpstan',
            rule: 'example.rule',
            category: 'design',
            severity: 'high',
            confidence: 0.9,
            message: 'Example finding.',
            file: 'src/Example.php',
            line: 12,
        );

        self::assertSame('phpstan', $finding->jsonSerialize()['source']);
        self::assertSame(0.9, $finding->jsonSerialize()['confidence']);
    }

    public function testItRejectsInvalidConfidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Finding('test', 'rule', 'design', 'high', 1.1, 'Invalid confidence');
    }
}
