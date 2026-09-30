<?php

declare(strict_types=1);

namespace App\Inspecting;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Boots the standalone Symfony verification runtime for the Inspecting engine.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
