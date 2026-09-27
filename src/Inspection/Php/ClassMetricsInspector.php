<?php

declare(strict_types=1);

namespace App\Inspection\Php;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

final readonly class ClassMetricsInspector
{
    /**
     * @return array{
     *     constructorDependencies:int,
     *     implementedInterfaces:int,
     *     hasParent:bool
     * }
     */
    public function inspect(Class_ $class): array
    {
        return [
            'constructorDependencies' => $this->constructorDependencyCount($class->getMethod('__construct')),
            'implementedInterfaces' => count($class->implements),
            'hasParent' => $class->extends instanceof Name,
        ];
    }

    private function constructorDependencyCount(?ClassMethod $constructor): int
    {
        if (!$constructor instanceof ClassMethod) {
            return 0;
        }

        return count(array_filter(
            $constructor->params,
            fn(Param $param): bool => $this->isObjectDependency($param->type),
        ));
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
