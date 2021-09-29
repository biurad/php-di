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

namespace Rade\DI;

use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\ResetInterface;

/**
 * A container supporting multiple PSR-11 containers.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class ContextContainer implements ContainerInterface, ResetInterface
{
    /** @var ContainerInterface[] A list of PSR-11 containers */
    private array $containers = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $id)
    {
        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $container->get($id);
            }

            if ($container instanceof Container && $container->typed($id)) {
                try {
                    return $container->autowired($id, true);
                } catch (ContainerResolutionException $e) {
                    // Skip this exception.
                }
            }
        }

        throw new NotFoundServiceException(\sprintf('Requested service "%s" was not found in any container. Did you forget to set it?', $id));
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        // A container such as Symfony DI support reset ...
        foreach ($this->containers as $container) {
            if ($container instanceof ResetInterface) {
                $container->reset();
            }
        }

        $this->containers = [];
    }

    /**
     * Attaches a container to the composite container.
     */
    public function attach(ContainerInterface $container): void
    {
        $this->containers[] = $container;
    }

    /**
     * Removes a container from the list of containers.
     */
    public function detach(ContainerInterface $container): void
    {
        foreach ($this->containers as $i => $c) {
            if ($container === $c) {
                unset($this->containers[$i]);

                break;
            }
        }
    }
}
