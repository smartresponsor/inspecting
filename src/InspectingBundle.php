<?php

declare(strict_types=1);

namespace App\Inspecting;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Exposes Inspecting as a reusable Symfony bundle without moving analysis ownership into the host.
 */
final class InspectingBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
