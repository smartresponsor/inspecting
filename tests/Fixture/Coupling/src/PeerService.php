<?php

declare(strict_types=1);

namespace Fixture\Coupling;

final class PeerService
{
    public function __construct(private readonly SharedDependency $dependency) {}

    public function dependency(): SharedDependency
    {
        return $this->dependency;
    }
}
