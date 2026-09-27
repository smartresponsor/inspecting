<?php

declare(strict_types=1);

namespace Fixture;

use Psr\Container\ContainerInterface;

final class LocatorConsumer
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function resolve(string $id): object
    {
        $service = $this->container->get($id);

        if (!is_object($service)) {
            throw new \RuntimeException('Expected object service.');
        }

        return $service;
    }
}
