<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use App\Inspecting\Inspector\EngineClassMetricsInspector;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class EngineClassMetricsInspectorTest extends TestCase
{
    public function testConstructorCountsOnlyObjectDependenciesAcrossTypeShapes(): void
    {
        $class = $this->parse('class Example extends Base implements First, Second {
            public function __construct(
                int $number,
                string $text,
                $untyped,
                Dependency $direct,
                ?Dependency $nullable,
                Dependency|false $union,
                Dependency&Contract $intersection,
                int|float $numericUnion,
            ) {}
        }');

        self::assertSame([
            'constructorDependencies' => 4,
            'implementedInterfaces' => 2,
            'hasParent' => true,
        ], (new EngineClassMetricsInspector())->inspect($class));
    }

    public function testClassWithoutConstructorOrInheritanceHasZeroMetrics(): void
    {
        self::assertSame([
            'constructorDependencies' => 0,
            'implementedInterfaces' => 0,
            'hasParent' => false,
        ], (new EngineClassMetricsInspector())->inspect($this->parse('class EmptyClass {}')));
    }

    private function parse(string $source): Class_
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$source);
        self::assertNotNull($nodes);
        self::assertInstanceOf(Class_::class, $nodes[0]);

        return $nodes[0];
    }
}
