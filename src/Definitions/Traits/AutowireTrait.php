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

namespace Rade\DI\Definitions\Traits;

use PhpParser\Node\{Name, UnionType};
use Rade\DI\Resolvers\Resolver;

/**
 * This trait adds a autowiring functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait AutowireTrait
{
    /** @var array<int,string> */
    private array $types = [];

    private bool $autowired = false;

    /**
     * {@inheritdoc}
     */
    public function autowire(array $types = [])
    {
        if ([] === $types) {
            $types = Resolver::autowireService($this->getEntity(), false, isset($this->innerId) ? $this->container : null);
        }

        if (isset($this->innerId)) {
            $this->container->type($this->innerId, $types);
        }

        $this->autowired = true;
        $this->types = $types;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function typed(array $to)
    {
        if (isset($this->innerId)) {
            $this->container->type($this->innerId, $to);
        }

        foreach ($to as $typed) {
            $this->types[] = $typed;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isTyped(): bool
    {
        return !empty($this->types);
    }

    /**
     * {@inheritdoc}
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Triggers a return type to definition's method.
     */
    public function triggerReturnType(\PhpParser\Builder\Method $defNode): void
    {
        $defTyped = [];

        foreach ($this->types as $offset => $typed) {
            if (\class_exists($typed)) {
                $defTyped[] = $typed;

                foreach (\array_slice($this->types, $offset + 1) as $interface) {
                    if (!\is_subclass_of($typed, $interface)) {
                        $defTyped[] = $interface;
                    }
                }

                break;
            }
        }

        if ([] === $defTyped) {
            $defTyped = $this->types;

            if (empty($defTyped)) {
                $this->types = Resolver::autowireService($this->getEntity(), true, isset($this->innerId) ? $this->container : null);

                if (!empty($this->types)) {
                    $this->triggerReturnType($defNode);
                }

                return;
            }
        }

        $defTyped = \array_unique($defTyped); // Fix same type repeating.

        if (1 === count($defTyped) || \PHP_VERSION_ID < 80000) {
            $defNode->setReturnType(\current($defTyped));
        } else {
            $defNode->setReturnType(new UnionType(\array_map(fn ($type) => new Name($type), $defTyped)));
        }
    }
}
