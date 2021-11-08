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

use Rade\DI\Exceptions\ContainerResolutionException;

/**
 * This trait adds aliasing functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait AliasTrait
{
    /** @var array<int,string> alias => service name */
    protected array $aliases = [];

    /**
     * Remove an aliased definition.
     */
    final public function removeAlias(string $id): void
    {
        unset($this->aliases[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function alias(string $id, string $serviceId): void
    {
        if ($id === $serviceId) {
            throw new \LogicException(\sprintf('[%s] is aliased to itself.', $id));
        }

        if (isset($this->types) && $typed = $this->typed($serviceId, true)) {
            if (\count($typed) > 1) {
                throw new ContainerResolutionException(\sprintf('Aliasing an alias of "%s" on a multiple defined type "%s" is not allowed.', $id, $serviceId));
            }

            $serviceId = $typed[0];
        }

        if (!$this->has($serviceId)) {
            throw $this->createNotFound($serviceId);
        }

        $this->aliases[$id] = $this->aliases[$serviceId] ?? $serviceId;
    }

    /**
     * {@inheritdoc}
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
