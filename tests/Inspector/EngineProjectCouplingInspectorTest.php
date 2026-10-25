<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use App\Inspecting\Inspector\EngineProjectCouplingInspector;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class EngineProjectCouplingInspectorTest extends TestCase
{
    public function testGraphDistinguishesInternalExternalAndRepeatedReferences(): void
    {
        $inspector = $this->inspect('<?php
            namespace Fixture;
            class First {
                public function run(Second $second): External {
                    new Second();
                    return External::build();
                }
            }
            class Second {
                public function run(First $first): void {
                    if ($first instanceof First) {}
                }
            }
        ');

        self::assertSame([
            'maxFanOut' => 2,
            'maxFanIn' => 1,
            'maxInheritanceDepth' => 0,
        ], $inspector->metrics());

        $nodes = $inspector->graph()['nodes'];
        self::assertSame(['Fixture\\First', 'Fixture\\Second'], array_column($nodes, 'class'));
        self::assertSame(['Fixture\\External', 'Fixture\\Second'], $nodes[0]['dependencies']);
        self::assertSame(['Fixture\\Second'], $nodes[0]['internalDependencies']);
        self::assertSame(['Fixture\\External'], $nodes[0]['externalDependencies']);
        self::assertSame(1, $nodes[0]['fanIn']);
        self::assertSame(1, $nodes[1]['fanIn']);
    }

    public function testInheritanceCycleIsBoundedAndExternalParentCountsAsOne(): void
    {
        $inspector = $this->inspect('<?php
            namespace Fixture;
            class Alpha extends Beta {}
            class Beta extends Alpha {}
            class Gamma extends Outside {}
        ');

        self::assertSame(2, $inspector->metrics()['maxInheritanceDepth']);
        $nodes = $inspector->graph()['nodes'];
        self::assertSame('Fixture\\Beta', $nodes[0]['parent']);
        self::assertSame('Fixture\\Alpha', $nodes[1]['parent']);
        self::assertSame(['Fixture\\Outside'], $nodes[2]['externalDependencies']);
        self::assertSame(1, $nodes[2]['inheritanceDepth']);
    }

    public function testEmptyGraphAndSelfReferences(): void
    {
        $empty = new EngineProjectCouplingInspector();
        self::assertSame(['maxFanOut' => 0, 'maxFanIn' => 0, 'maxInheritanceDepth' => 0], $empty->metrics());

        $inspector = $this->inspect('<?php namespace Fixture;
            class Standalone {
                public function run(self $self): static {
                    return static::create();
                }
            }
        ');
        self::assertSame([], $inspector->graph()['nodes'][0]['dependencies']);
    }

    private function inspect(string $source): EngineProjectCouplingInspector
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertNotNull($nodes);
        $traverser = new NodeTraverser(new NameResolver());
        $inspector = new EngineProjectCouplingInspector();
        $inspector->collect(array_values($traverser->traverse($nodes)));

        return $inspector;
    }
}
