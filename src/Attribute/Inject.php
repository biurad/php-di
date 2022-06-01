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

use Rade\DI\Definitions\Parameter;
use Rade\DI\Definitions\TaggedLocator;
use Rade\DI\Resolver;

/**
 * Marks a property or method as an injection point.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_PARAMETER)]
final class Inject
{
    public const
        VALUE = 0,
        REFERENCE = 1,
        PARAMETER = 2;

    private int $type;

    /** @var mixed */
    private $value;

    /**
     * @param mixed $value
     */
    public function __construct($value = null, $type = self::REFERENCE)
    {
        $this->value = $value;
        $this->type = $type;
    }

    /**
     * Resolve the value of the injection point.
     *
     * @return mixed
     */
    public function resolve(Resolver $resolver, string $typeName = null)
    {
        if (null === $value = $this->value ?? $typeName) {
            return null;
        }

        if ($value instanceof TaggedLocator) {
            throw new \LogicException(\sprintf('Use the #[%s] attribute instead for lazy loading tags.', Tagged::class));
        }

        if (self::REFERENCE === $this->type) {
            return $resolver->resolveReference($value);
        }

        if (self::PARAMETER === $this->type) {
            $value = new Parameter($value, true);
        }

        return $resolver->resolve($value);
    }
}
