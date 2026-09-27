<?php

declare(strict_types=1);

namespace Fixture\Coupling;

final class LeafService extends MiddleService
{
    public function __construct(
        private readonly SharedDependency $dependency,
        private readonly ExternalDependency $external,
    ) {}

    /** @return list<object> */
    public function dependencies(): array
    {
        return [$this->dependency, $this->external];
    }
}
