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

namespace Rade\DI\Definitions;

use Rade\DI\Container;

/**
 * Creates a lazy iterator by tag name.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class TaggedLocator
{
    private ?string $indexAttribute;

    /**
     * @param string            $tag                   The name of the tag identifying the target services
     * @param string|null       $indexAttribute        The name of the attribute that defines the key referencing each service in the tagged collection
     * @param bool              $needsIndexes          Whether indexes are required and should be generated when computing the map
     * @param array<int,string> $exclude               Services to exclude from the iterator
     */
    public function __construct(private string $tag, string $indexAttribute = null, bool $needsIndexes = false, private array $exclude = [])
    {
        if (null === $indexAttribute && $needsIndexes) {
            $indexAttribute = \preg_match('/[^.]++$/', $tag, $m) ? $m[0] : $tag;
        }

        $this->tag = $tag;
        $this->exclude = $exclude;
        $this->indexAttribute = $indexAttribute;
    }

    /**
     * @return array<int|string,Reference|Statement>
     */
    public function resolve(Container $container): array
    {
        $i = 0;
        $services = $refs = [];

        foreach ($container->tagged($this->tag) as $serviceId => $attributes) {
            if (\in_array($serviceId, $this->exclude, true)) {
                continue;
            }

            $index = $priority = null;

            if (\is_array($attributes)) {
                if (null !== $this->indexAttribute && isset($attributes[$this->indexAttribute])) {
                    $index = $attributes[$this->indexAttribute];
                }

                if (isset($attributes['priority'])) {
                    $priority = $attributes['priority'];
                }
            }

            $services[] = [$priority ?? 0, ++$i, $index, $serviceId];
        }

        \uasort($services, static fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        foreach ($services as [, , $index, $serviceId]) {
            $reference = $container->has($serviceId) ? new Reference($serviceId) : new Statement($serviceId);

            if (null === $index) {
                $refs[] = $reference;
            } else {
                $refs[$index] = $reference;
            }
        }

        return $refs;
    }
}
