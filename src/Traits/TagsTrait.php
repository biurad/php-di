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

/**
 * This trait adds tagging functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait TagsTrait
{
    /** @var array[] tag name => service name => tag value */
    protected array $tags = [];

    /**
     * Remove a registered tag.
     */
    public function removeTag(string $tag, string ...$only): void
    {
        if (empty($only)) {
            unset($this->tags[$tag]);

            return;
        }

        foreach ($this->tags[$tag] ?? [] as $values) {
            foreach ($values as $id => $value) {
                if (\in_array($id, $only, true)) {
                    unset($this->tags[$tag][$id]);
                }
            }
        }
    }

    /**
     * Assign a set a tag to a service.
     *
     * @param array<int,string>|string $tags
     */
    public function tag(string $serviceId, string|array $tags, mixed $value = true): void
    {
        foreach ((array) $tags as $tag) {
            $this->tags[$tag][$serviceId] = $value;
        }
    }

    /**
     * Assign a set of tags to service(s).
     *
     * Example:
     * ```php
     *     $container->set('foo', FooClass::class);
     *     $container->set('bar', BarClass::class);
     *     $container->set('baz', BazClass::class);
     *
     *     $container->tags(['my.tag' => ['foo' => ['hello' => 'world'], 'bar'], 'my.tag2' => 'baz']);
     * ```
     *
     * @param array<string,array<int|string,mixed>|string> $tags
     */
    public function tags(array $tags): void
    {
        foreach ($tags as $tag => $serviceIds) {
            foreach ((array) $serviceIds as $serviceId => $value) {
                if (\is_numeric($serviceId)) {
                    [$serviceId, $value] = [$value, true];
                }
                $this->tag($serviceId, $tag, $value);
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     * If $name is a registered service id, check if service has tags.
     *
     * Example:
     * ```php
     *     $container->set('foo', FooClass::class);
     *     $container->tag('foo', 'my.tag', ['hello' => 'world']);
     *
     *     $taggedIds = $container->tagged('my.tag');
     *     foreach ($taggedIds as $serviceId => $value) {
     *         echo $value['hello'];
     *     }
     * ```
     *
     * @return array<string,mixed>|mixed An array of service ids as key mapping to tagged value
     */
    public function tagged(string $tag, string $serviceId = null): mixed
    {
        $tags = $this->tags[$tag] ?? [];

        if (null !== $serviceId) {
            return $tags[$serviceId] ?? null;
        }

        return $tags;
    }

    /**
     * Get all tags.
     *
     * @return array<string,array<string, mixed>>
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
