<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Analyzer\PhpStructureAnalyzer;
use PHPUnit\Framework\TestCase;

final class PhpStructureAnalyzerTest extends TestCase
{
    public function testItReportsExcessiveConstructorDependencies(): void
    {
        $target = __DIR__ . '/../Fixture/Structure';
        $result = (new PhpStructureAnalyzer())->inspect($target);

        self::assertSame('php-structure', $result->analyzer);
        self::assertSame(1, $result->metrics['classes']);
        self::assertSame(9, $result->metrics['maxConstructorDependencies']);
        self::assertCount(1, $result->findings);
        self::assertSame('solid.srp.excessive-constructor-dependencies', $result->findings[0]->rule);
        self::assertSame(9, $result->findings[0]->evidence['dependencyCount']);
    }
}
