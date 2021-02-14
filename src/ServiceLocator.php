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

use DivineNii\Invoker\CallableReflection;
use Psr\Container\ContainerExceptionInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Symfony\Contracts\Service\ServiceLocatorTrait;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Rade PSR-11 service locator.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ServiceLocator implements ServiceProviderInterface
{
    use ServiceLocatorTrait;

    /**
     * {@inheritdoc}
     */
    public function get($id)
    {
        if (!isset($this->factories[$id])) {
            throw $this->createNotFoundException($id);
        }

        if (isset($this->loading[$id])) {
            $ids = array_values($this->loading);
            $ids = \array_slice($this->loading, (int) array_search($id, $ids, true));
            $ids[] = $id;

            throw $this->createCircularReferenceException($id, $ids);
        }

        $this->loading[$id] = $id;

        try {
            $service = $this->factories[$id];

            return \is_callable($service) ? $service() : $service;
        } finally {
            unset($this->loading[$id]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getProvidedServices(): array
    {
        if (null === $this->providedTypes) {
            $this->providedTypes = [];

            foreach ($this->factories as $name => $factory) {
                if (\is_callable($factory)) {
                    $type = CallableReflection::create($factory)->getReturnType();

                    $this->providedTypes[$name] = $type ? ($type->allowsNull() ? '?' : '') . ($type instanceof \ReflectionNamedType ? $type->getName() : $type) : '?';
                } elseif (\is_object($factory) && !$factory instanceof \stdClass) {
                    $this->providedTypes[$name] = \get_class($factory);
                } else {
                    $this->providedTypes[$name] = '?';
                }
            }
        }

        return $this->providedTypes;
    }

    private function createCircularReferenceException(string $id, array $path): ContainerExceptionInterface
    {
        return new CircularReferenceException(\sprintf(
            'Circular reference detected for service "%s", path: "%s".',
            $id,
            implode(' -> ', $path)
        ));
    }
}
