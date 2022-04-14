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

namespace Rade\DI\Attribute;

use Rade\DI\AbstractContainer;
use Rade\DI\Definitions\TaggedLocator;

/**
 * Creates a lazy iterator by tag name.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Tagged
{
    private TaggedLocator $value;

    /**
     * @param string            $tag                   The name of the tag identifying the target services
     * @param string|null       $indexAttribute        The name of the attribute that defines the key referencing each service in the tagged collection
     * @param bool              $needsIndexes          Whether indexes are required and should be generated when computing the map
     * @param array<int,string> $exclude               Services to exclude from the iterator
     */
    public function __construct(string $tag, string $indexAttribute = null, bool $needsIndexes = false, array $exclude = [])
    {
        $this->value = new TaggedLocator($tag, $indexAttribute, $needsIndexes, $exclude);
    }

    public function getValues(AbstractContainer $container): array
    {
        return $this->value->resolve($container);
    }
}
