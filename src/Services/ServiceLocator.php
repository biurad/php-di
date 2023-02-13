<?php

declare(strict_types=1);

/*
 * This file is part of DivineNii opensource projects.
 *
 * PHP version 7.4 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2021 DivineNii (https://divinenii.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rade\DI\Services;

use Psr\Container\ContainerExceptionInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Symfony\Contracts\Service\{ServiceLocatorTrait, ServiceProviderInterface as ServiceProviderContext};

/**
 * Rade PSR-11 service locator.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ServiceLocator implements ServiceProviderContext
{
    use ServiceLocatorTrait;

    /**
     * @param array<int,string> $path
     */
    private function createCircularReferenceException(string $id, array $path): ContainerExceptionInterface
    {
        return new CircularReferenceException($id, $path);
    }
}
