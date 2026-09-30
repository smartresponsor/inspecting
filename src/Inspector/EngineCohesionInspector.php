<?php

declare(strict_types=1);

namespace App\Inspecting\Inspector;

use App\Inspecting\ValueObject\EngineFinding;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Evaluates method-to-property relationships inside a parsed class to detect low-cohesion structures and fragmented responsibilities.
 */
final readonly class EngineCohesionInspector
{
    private const int MIN_PARTICIPATING_METHODS = 4;
    private const int COMPONENT_THRESHOLD = 3;

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 *
     * @return list<EngineFinding>
     */
    public function inspect(Class_ $class, string $file): array
    {
        $methodProperties = [];

        foreach ($class->getMethods() as $method) {
            if (!$this->isCohesionCandidate($method)) {
                continue;
            }

            $properties = $this->collectThisProperties($method->stmts ?? []);

            if ([] !== $properties) {
                $methodProperties[$method->name->toString()] = $properties;
            }
        }

        if (count($methodProperties) < self::MIN_PARTICIPATING_METHODS) {
            return [];
        }

        $componentCount = $this->countComponents($methodProperties);

        if ($componentCount < self::COMPONENT_THRESHOLD) {
            return [];
        }

        $propertyNames = [];

        foreach ($methodProperties as $properties) {
            foreach ($properties as $property) {
                $propertyNames[$property] = true;
            }
        }

        $className = $class->name?->toString() ?? 'anonymous';

        return [new EngineFinding(
            source: 'php-structure',
            rule: 'solid.srp.low-property-cohesion',
            category: 'design',
            severity: 'medium',
            confidence: 0.76,
            message: sprintf(
                '%s has %d independent property-usage method groups across %d participating methods; review responsibility cohesion.',
                $className,
                $componentCount,
                count($methodProperties),
            ),
            evidence: [
                'participatingMethods' => count($methodProperties),
                'propertyCount' => count($propertyNames),
                'components' => $componentCount,
            ],
            file: $file,
            line: $class->getStartLine(),
            endLine: $class->getEndLine(),
        )];
    }

    private function isCohesionCandidate(ClassMethod $method): bool
    {
        if ($method->isStatic() || '__construct' === $method->name->toString()) {
            return false;
        }

        return count($method->stmts ?? []) > 1;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function collectThisProperties(array $values): array
    {
        $properties = [];

        foreach ($values as $value) {
            if (!$value instanceof Node) {
                if (is_array($value)) {
                    foreach ($this->collectThisProperties($value) as $property) {
                        $properties[$property] = true;
                    }
                }

                continue;
            }

            if (
                $value instanceof PropertyFetch
                && $value->var instanceof Variable
                && 'this' === $value->var->name
                && $value->name instanceof Identifier
            ) {
                $properties[$value->name->toString()] = true;
            }

            foreach ($value->getSubNodeNames() as $subNodeName) {
                $subNode = $value->{$subNodeName};

                if ($subNode instanceof Node) {
                    foreach ($this->collectThisProperties([$subNode]) as $property) {
                        $properties[$property] = true;
                    }
                } elseif (is_array($subNode)) {
                    foreach ($this->collectThisProperties($subNode) as $property) {
                        $properties[$property] = true;
                    }
                }
            }
        }

        return array_keys($properties);
    }

    /**
     * @param array<string, list<string>> $methodProperties
     */
    private function countComponents(array $methodProperties): int
    {
        $remaining = array_fill_keys(array_keys($methodProperties), true);
        $components = 0;

        while ([] !== $remaining) {
            ++$components;
            $queue = [array_key_first($remaining)];

            while ([] !== $queue) {
                $method = array_pop($queue);

                if (!isset($remaining[$method])) {
                    continue;
                }

                unset($remaining[$method]);

                foreach (array_keys($remaining) as $candidate) {
                    if ([] !== array_intersect($methodProperties[$method], $methodProperties[$candidate])) {
                        $queue[] = $candidate;
                    }
                }
            }
        }

        return $components;
    }
}
