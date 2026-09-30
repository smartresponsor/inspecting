<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Analyzer\EnginePhpStructureAnalyzer;
use PHPUnit\Framework\TestCase;

final class PhpStructureAnalyzerTest extends TestCase
{
    public function testItReportsExcessiveConstructorDependencies(): void
    {
        $target = __DIR__ . '/../Fixture/Structure';
        $result = (new EnginePhpStructureAnalyzer())->inspect($target);

        self::assertSame('php-structure', $result->analyzer);
        self::assertSame(1, $result->metrics['classes']);
        self::assertSame(9, $result->metrics['maxConstructorDependencies']);
        self::assertCount(1, $result->findings);
        self::assertSame('solid.srp.excessive-constructor-dependencies', $result->findings[0]->rule);
        self::assertSame(9, $result->findings[0]->evidence['dependencyCount']);
    }

    public function testItReportsProjectCouplingAndInheritanceMetrics(): void
    {
        $target = __DIR__ . '/../Fixture/Coupling';
        $result = (new EnginePhpStructureAnalyzer())->inspect($target);

        self::assertSame(3, $result->metrics['maxFanOut']);
        self::assertSame(2, $result->metrics['maxFanIn']);
        self::assertSame(2, $result->metrics['maxInheritanceDepth']);
        self::assertCount(0, $result->findings);
        self::assertCount(1, $result->rawReportReferences);

        $graphJson = file_get_contents($result->rawReportReferences[0]);
        self::assertIsString($graphJson);
        $graph = json_decode($graphJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($graph);
        self::assertIsArray($graph['nodes'] ?? null);
        self::assertCount(6, $graph['nodes']);

        $leafNodes = array_values(array_filter(
            $graph['nodes'],
            static fn(mixed $node): bool => is_array($node) && 'Fixture\\Coupling\\LeafService' === ($node['class'] ?? null),
        ));
        self::assertCount(1, $leafNodes);
        self::assertSame(3, $leafNodes[0]['fanOut']);
        self::assertSame(0, $leafNodes[0]['fanIn']);
        self::assertSame(2, $leafNodes[0]['inheritanceDepth']);
        self::assertSame([], $leafNodes[0]['externalDependencies']);
    }
}
