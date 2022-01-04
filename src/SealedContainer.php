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
use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException, NotFoundServiceException};

/**
 * A fully strict PSR-11 Container Implementation.
 *
 * This class is meant to be used as parent class for container's builder
 * compiled container class.
 *
 * Again, all services declared, should be autowired. Lazy services are not supported.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class SealedContainer implements ContainerInterface
{
    protected array $loading = [], $services = [], $privates = [], $methodsMap = [], $aliases = [], $types = [], $tags = [];

    public function __construct()
    {
        $this->services[AbstractContainer::SERVICE_CONTAINER] = $this;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return \array_key_exists($id = $this->aliases[$id] ?? $id, $this->methodsMap) || isset($this->types[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id)
    {
        if ($nullOnInvalid = '?' === $id[0]) {
            $id = \substr($id, 1);
        }

        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (\array_key_exists($id, $this->methodsMap)) {
            if (isset($this->loading[$id])) {
                throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
            }

            $this->loading[$id] = true;

            try {
                return $this->{$this->methodsMap[$id]}();
            } finally {
                unset($this->loading[$id]);
            }
        }

        if (\array_key_exists($id, $this->aliases)) {
            return $this->services[$id = $this->aliases[$id]] ?? $this->get($id);
        }

        return $this->doGet($id, $nullOnInvalid);
    }

    /**
     * Returns a set of service definition's belonging to a tag.
     *
     * @param string $tagName
     * @param string|null $serviceId If provided, tag value will return instead
     *
     * @return mixed
     */
    public function tagged(string $tagName, ?string $serviceId = null)
    {
        $tags = $this->tags[$tagName] ?? [];

        return null !== $serviceId ? $tags[$serviceId] ?? [] : $tags;
    }

    /**
     * Return the resolved service of an entry.
     *
     * @return mixed
     */
    protected function doGet(string $id, bool $nullOnInvalid)
    {
        if (\preg_match('/\[(.*?)?\]$/', $id, $matches, \PREG_UNMATCHED_AS_NULL)) {
            $autowired = $this->types[\str_replace($matches[0], '', $oldId = $id)] ?? [];

            if (!empty($autowired)) {
                if (isset($matches[1])) {
                    return $this->services[$oldId] = \array_map([$this, 'get'], $autowired);
                }

                foreach ($autowired as $serviceId) {
                    if ($serviceId === $matches[1]) {
                        return $this->get($this->aliases[$oldId] = $serviceId);
                    }
                }
            }
        } elseif (!empty($autowired = $this->types[$id] ?? [])) {
            if (\count($autowired) > 1) {
                \natsort($autowired);
                $autowired = \count($autowired) <= 3 ? \implode(', ', $autowired) : $autowired[0] . ', ...' . \end($autowired);

                throw new ContainerResolutionException(\sprintf('Multiple services of type %s found: %s.', $id, $autowired));
            }

            if (!isset($this->aliases[$id])) {
                $this->aliases[$id] = $autowired[0];
            }

            return $this->services[$autowired[0]] ?? $this->get($autowired[0]);
        } elseif ($nullOnInvalid) {
            return null;
        }

        throw new NotFoundServiceException(\sprintf('The "%s" requested service is not defined in container.', $id));
    }

    private function __clone()
    {
    }
}
