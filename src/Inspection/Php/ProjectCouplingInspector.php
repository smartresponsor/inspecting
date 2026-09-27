<?php

declare(strict_types=1);

namespace App\Inspection\Php;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;

final class ProjectCouplingInspector
{
    /** @var array<string, array<string, true>> */
    private array $dependencies = [];

    /** @var array<string, string|null> */
    private array $parents = [];

    /**
     * @param list<Node> $nodes
     */
    public function collect(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_ && $node->name instanceof Identifier) {
                $this->collectClass($node);
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $value = $node->{$subNodeName};

                if ($value instanceof Node) {
                    $this->collect([$value]);
                } elseif (is_array($value)) {
                    $children = array_values(array_filter(
                        $value,
                        static fn(mixed $item): bool => $item instanceof Node,
                    ));
                    /** @var list<Node> $children */
                    $this->collect($children);
                }
            }
        }
    }

    /**
     * @return array{maxFanOut:int, maxFanIn:int, maxInheritanceDepth:int}
     */
    public function metrics(): array
    {
        $knownClasses = array_fill_keys(array_keys($this->dependencies), true);
        $fanIn = array_fill_keys(array_keys($this->dependencies), 0);
        $maxFanOut = 0;

        foreach ($this->dependencies as $class => $dependencies) {
            unset($dependencies[$class]);
            $maxFanOut = max($maxFanOut, count($dependencies));

            foreach (array_keys($dependencies) as $dependency) {
                if (isset($knownClasses[$dependency])) {
                    ++$fanIn[$dependency];
                }
            }
        }

        $maxInheritanceDepth = 0;
        foreach (array_keys($this->parents) as $class) {
            $maxInheritanceDepth = max($maxInheritanceDepth, $this->inheritanceDepth($class));
        }

        return [
            'maxFanOut' => $maxFanOut,
            'maxFanIn' => [] === $fanIn ? 0 : max($fanIn),
            'maxInheritanceDepth' => $maxInheritanceDepth,
        ];
    }

    private function collectClass(Class_ $class): void
    {
        $className = $class->namespacedName?->toString();
        if (null === $className) {
            return;
        }

        $this->dependencies[$className] ??= [];
        $this->parents[$className] = $this->name($class->extends);
        $this->addName($className, $class->extends);

        foreach ($class->implements as $interface) {
            $this->addName($className, $interface);
        }

        foreach ($class->getMethods() as $method) {
            foreach ($method->params as $param) {
                $this->addType($className, $param->type);
            }
            $this->addType($className, $method->returnType);
        }

        foreach ($class->getProperties() as $property) {
            $this->addType($className, $property->type);
        }

        $this->collectClassReferences($className, array_values($class->stmts));
    }

    /**
     * @param list<Node> $nodes
     */
    private function collectClassReferences(string $className, array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->collectDirectReferences($className, $node);

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $value = $node->{$subNodeName};

                if ($value instanceof Node) {
                    $this->collectClassReferences($className, [$value]);
                    continue;
                }

                if (is_array($value)) {
                    $children = array_values(array_filter(
                        $value,
                        static fn(mixed $item): bool => $item instanceof Node,
                    ));
                    /** @var list<Node> $children */
                    $this->collectClassReferences($className, $children);
                }
            }
        }
    }

    private function collectDirectReferences(string $className, Node $node): void
    {
        match ($node::class) {
            New_::class => $this->collectNewReference($className, $node),
            StaticCall::class => $this->collectStaticCallReference($className, $node),
            ClassConstFetch::class => $this->collectClassConstantReference($className, $node),
            Instanceof_::class => $this->collectInstanceofReference($className, $node),
            Catch_::class => $this->collectCatchReferences($className, $node),
            TraitUse::class => $this->collectTraitReferences($className, $node),
            ClassMethod::class => $this->collectMethodTypeReferences($className, $node),
            Property::class => $this->collectPropertyTypeReference($className, $node),
            default => null,
        };
    }

    private function collectNewReference(string $className, Node $node): void
    {
        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->addName($className, $node->class);
        }
    }

    private function collectStaticCallReference(string $className, Node $node): void
    {
        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $this->addName($className, $node->class);
        }
    }

    private function collectClassConstantReference(string $className, Node $node): void
    {
        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            $this->addName($className, $node->class);
        }
    }

    private function collectInstanceofReference(string $className, Node $node): void
    {
        if ($node instanceof Instanceof_ && $node->class instanceof Name) {
            $this->addName($className, $node->class);
        }
    }

    private function collectCatchReferences(string $className, Node $node): void
    {
        if (!$node instanceof Catch_) {
            return;
        }

        foreach ($node->types as $type) {
            $this->addName($className, $type);
        }
    }

    private function collectTraitReferences(string $className, Node $node): void
    {
        if (!$node instanceof TraitUse) {
            return;
        }

        foreach ($node->traits as $trait) {
            $this->addName($className, $trait);
        }
    }

    private function collectMethodTypeReferences(string $className, Node $node): void
    {
        if (!$node instanceof ClassMethod) {
            return;
        }

        foreach ($node->params as $param) {
            $this->addType($className, $param->type);
        }
        $this->addType($className, $node->returnType);
    }

    private function collectPropertyTypeReference(string $className, Node $node): void
    {
        if ($node instanceof Property) {
            $this->addType($className, $node->type);
        }
    }

    private function addType(string $className, Node|Identifier|ComplexType|null $type): void
    {
        if ($type instanceof Name) {
            $this->addName($className, $type);
        } elseif ($type instanceof NullableType) {
            $this->addType($className, $type->type);
        } elseif ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $nestedType) {
                $this->addType($className, $nestedType);
            }
        }
    }

    private function addName(string $className, ?Name $name): void
    {
        $dependency = $this->name($name);
        if (null !== $dependency && $dependency !== $className) {
            $this->dependencies[$className][$dependency] = true;
        }
    }

    private function name(?Name $name): ?string
    {
        if (!$name instanceof Name) {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');
        $value = $resolved instanceof Name ? $resolved->toString() : $name->toString();

        return in_array(strtolower($value), ['self', 'static', 'parent'], true) ? null : $value;
    }

    /**
     * @param array<string, true> $seen
     */
    private function inheritanceDepth(string $class, array $seen = []): int
    {
        if (isset($seen[$class])) {
            return 0;
        }

        $parent = $this->parents[$class] ?? null;
        if (null === $parent) {
            return 0;
        }

        $seen[$class] = true;

        return 1 + (isset($this->parents[$parent]) ? $this->inheritanceDepth($parent, $seen) : 0);
    }
}
