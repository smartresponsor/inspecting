<?php

declare(strict_types=1);

namespace App\Inspection\Php;

use App\Inspection\Finding;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;

final readonly class ClassDesignInspector
{
    private const int CONSTRUCTOR_DEPENDENCY_THRESHOLD = 8;
    private const int CLASS_LINE_THRESHOLD = 400;
    private const int PUBLIC_METHOD_THRESHOLD = 15;
    private const int INTERFACE_METHOD_THRESHOLD = 10;

    /**
     * @return list<Finding>
     */
    public function inspectClass(Class_ $class, string $file): array
    {
        $className = $class->name?->toString() ?? 'anonymous';

        return [
            ...$this->inspectConstructorDependencies($className, $class, $file),
            ...$this->inspectClassSize($className, $class, $file),
            ...$this->inspectPublicApi($className, $class, $file),
            ...$this->inspectServiceLocator($className, $class, $file),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function inspectInterface(Interface_ $interface, string $file): array
    {
        $methodCount = count($interface->getMethods());

        if ($methodCount <= self::INTERFACE_METHOD_THRESHOLD) {
            return [];
        }

        return [new Finding(
            source: 'php-structure',
            rule: 'solid.isp.oversized-interface',
            category: 'design',
            severity: 'medium',
            confidence: 0.8,
            message: sprintf(
                '%s declares %d methods; review whether clients are forced to depend on a broader contract than necessary.',
                $interface->name?->toString() ?? 'anonymous-interface',
                $methodCount,
            ),
            evidence: ['methodCount' => $methodCount],
            file: $file,
            line: $interface->getStartLine(),
            endLine: $interface->getEndLine(),
        )];
    }

    /**
     * @return list<Finding>
     */
    private function inspectConstructorDependencies(string $className, Class_ $class, string $file): array
    {
        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return [];
        }

        $dependencyCount = count(array_filter(
            $constructor->params,
            fn(Param $param): bool => $this->isObjectDependency($param->type),
        ));

        if ($dependencyCount <= self::CONSTRUCTOR_DEPENDENCY_THRESHOLD) {
            return [];
        }

        return [new Finding(
            source: 'php-structure',
            rule: 'solid.srp.excessive-constructor-dependencies',
            category: 'design',
            severity: $dependencyCount >= 12 ? 'high' : 'medium',
            confidence: 0.78,
            message: sprintf('%s has %d constructor dependencies; review whether it owns too many responsibilities.', $className, $dependencyCount),
            evidence: ['dependencyCount' => $dependencyCount],
            file: $file,
            line: $constructor->getStartLine(),
        )];
    }

    /**
     * @return list<Finding>
     */
    private function inspectClassSize(string $className, Class_ $class, string $file): array
    {
        $classLines = $class->getEndLine() - $class->getStartLine() + 1;

        if ($classLines <= self::CLASS_LINE_THRESHOLD) {
            return [];
        }

        return [new Finding(
            source: 'php-structure',
            rule: 'smell.large-class',
            category: 'maintainability',
            severity: 'medium',
            confidence: 0.72,
            message: sprintf('%s spans %d lines; review cohesion and responsibility boundaries.', $className, $classLines),
            evidence: ['lines' => $classLines],
            file: $file,
            line: $class->getStartLine(),
            endLine: $class->getEndLine(),
        )];
    }

    /**
     * @return list<Finding>
     */
    private function inspectPublicApi(string $className, Class_ $class, string $file): array
    {
        $publicMethodCount = count(array_filter(
            $class->getMethods(),
            static fn(ClassMethod $method): bool => $method->isPublic(),
        ));

        if ($publicMethodCount <= self::PUBLIC_METHOD_THRESHOLD) {
            return [];
        }

        return [new Finding(
            source: 'php-structure',
            rule: 'smell.large-public-api',
            category: 'design',
            severity: 'medium',
            confidence: 0.7,
            message: sprintf('%s exposes %d public methods; review whether its public responsibility surface is too broad.', $className, $publicMethodCount),
            evidence: ['publicMethodCount' => $publicMethodCount],
            file: $file,
            line: $class->getStartLine(),
        )];
    }

    /**
     * @return list<Finding>
     */
    private function inspectServiceLocator(string $className, Class_ $class, string $file): array
    {
        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return [];
        }

        foreach ($constructor->params as $param) {
            if (
                0 === $param->flags
                || !$param->var instanceof Variable
                || !is_string($param->var->name)
                || !$param->type instanceof Name
                || !str_ends_with($param->type->toString(), 'ContainerInterface')
            ) {
                continue;
            }

            $propertyName = $param->var->name;

            if (!$this->classUsesContainerLookup($class, $propertyName)) {
                continue;
            }

            return [new Finding(
                source: 'php-structure',
                rule: 'solid.dip.service-locator',
                category: 'design',
                severity: 'high',
                confidence: 0.94,
                message: sprintf(
                    '%s injects ContainerInterface and resolves services dynamically through $this->%s; prefer explicit dependencies.',
                    $className,
                    $propertyName,
                ),
                evidence: ['property' => $propertyName],
                file: $file,
                line: $constructor->getStartLine(),
            )];
        }

        return [];
    }

    private function classUsesContainerLookup(Class_ $class, string $propertyName): bool
    {
        foreach ($class->getMethods() as $method) {
            if ($this->containsContainerLookup($method->stmts ?? [], $propertyName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function containsContainerLookup(array $values, string $propertyName): bool
    {
        foreach ($values as $value) {
            if (!$value instanceof Node) {
                if (is_array($value) && $this->containsContainerLookup($value, $propertyName)) {
                    return true;
                }

                continue;
            }

            if ($value instanceof MethodCall && $this->isContainerLookupCall($value, $propertyName)) {
                return true;
            }

            foreach ($value->getSubNodeNames() as $subNodeName) {
                $subNode = $value->{$subNodeName};

                if ($subNode instanceof Node && $this->containsContainerLookup([$subNode], $propertyName)) {
                    return true;
                }

                if (is_array($subNode) && $this->containsContainerLookup($subNode, $propertyName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isContainerLookupCall(MethodCall $call, string $propertyName): bool
    {
        if (!$call->var instanceof PropertyFetch || !$call->var->var instanceof Variable) {
            return false;
        }

        if ('this' !== $call->var->var->name || !$call->var->name instanceof Identifier) {
            return false;
        }

        if ($propertyName !== $call->var->name->toString() || !$call->name instanceof Identifier) {
            return false;
        }

        return in_array($call->name->toString(), ['get', 'has'], true);
    }

    private function isObjectDependency(Identifier|Name|ComplexType|null $type): bool
    {
        if ($type instanceof Name) {
            return true;
        }

        if ($type instanceof NullableType) {
            return $this->isObjectDependency($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                if ($this->isObjectDependency($innerType)) {
                    return true;
                }
            }
        }

        return false;
    }
}
