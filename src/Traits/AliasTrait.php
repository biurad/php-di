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

namespace Rade\DI\Traits;

use Rade\DI\Definitions\Reference;
use Rade\DI\Exceptions\ContainerResolutionException;

/**
 * This trait adds aliasing functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait AliasTrait
{
    /** @var array<string,string> alias => service name */
    protected array $aliases = [];

    /**
     * Remove an aliased definition.
     */
    public function removeAlias(string $id): void
    {
        unset($this->aliases[$id]);
    }

    /**
     * Marks an alias id to service id.
     *
     * @param string                       $id      The alias id
     * @param Reference|string $service The registered service id
     *
     * @throws \Rade\DI\Exceptions\NotFoundServiceException Service id is not found in container
     */
    public function alias(string $id, Reference|string $service): void
    {
        $service = \explode('@', (string) $service, 2);

        if ($id === $service[0]) {
            throw new ContainerResolutionException(\sprintf('Cannot alias "%s" to itself.', $id));
        }

        if ($this->has($service[0])) {
            $this->aliases[$id] = $this->aliases[$service[0]] ?? $service[0];
        } elseif ($typed = $this->typed($service[0], true)) {
            if (\count($typed) > 1) {
                throw new ContainerResolutionException(\sprintf('Aliasing an alias of "%s" on a multiple defined type "%s" is not allowed.', $id, $service[0]));
            }

            $service = $typed[\intval($service[1] ?? 0)] ?? throw new ContainerResolutionException(\sprintf('Missing types for "%s" alias "%s"', $service[0], $id));
            $this->aliases[$id] = $this->aliases[$service] ?? $service;
        } else {
            throw $this->createNotFound($service[0]);
        }
    }

    /**
     * Checks if a service definition has been aliased.
     *
     * @param string $id The registered service id
     */
    public function aliased(string $id): bool
    {
        foreach ($this->aliases as $serviceId) {
            if ($id === $serviceId) {
                return true;
            }
        }

        return false;
    }
}
