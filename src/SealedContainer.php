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
    protected array $loading = [], $services = [], $privates = [], $aliases = [], $types = [], $tags = [];

    public function __construct()
    {
        $this->services[AbstractContainer::SERVICE_CONTAINER] = $this;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return isset($this->types[$this->aliases[$id] ?? $id]);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        return $this->services[$id = $this->aliases[$id] ?? $id] ?? $this->doLoad($id, $invalidBehavior);
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
    protected function doLoad(string $id, int $invalidBehavior)
    {
        if (\array_key_exists($id, $this->types)) {
            if (1 === \count($autowired = $this->types[$id])) {
                return $this->get($autowired[1]);
            }

            if (AbstractContainer::IGNORE_MULTIPLE_SERVICE !== $invalidBehavior && AbstractContainer::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior) {
                \natsort($autowired);
                $autowired = \count($autowired) <= 3 ? \implode(', ', $autowired) : $autowired[0] . ', ...' . \end($autowired);

                throw new ContainerResolutionException(\sprintf('Multiple services of type %s found: %s.', $id, $autowired));
            }

            return \array_map([$this, 'get'], $autowired);
        }

        if (\preg_match('/\[(.*?)?\]$/', $id, $matches, \PREG_UNMATCHED_AS_NULL)) {
            $autowired = $this->types[\str_replace($matches[0], '', $id)] ?? [];

            if (!empty($autowired)) {
                if (\is_numeric($k = $matches[1])) {
                    $k = $autowired[$k] ?? null;
                }

                return $k ? $this->get($k) : \array_map([$this, 'get'], $autowired);
            }
        }

        if (AbstractContainer::NULL_ON_INVALID_SERVICE === $invalidBehavior) {
            return null;
        }

        throw new NotFoundServiceException(\sprintf('The "%s" requested service is not defined in container.', $id));
    }

    private function __clone()
    {
    }
}
