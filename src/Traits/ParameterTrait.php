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
 * This trait adds global config support as parameters.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ParameterTrait
{
    /** @var array<string,mixed> For handling a global config around services */
    public array $parameters = [];

    /**
     * Replaces "%value%" from container's parameters whose value is scalar.
     *
     * Example of usage:
     *
     * ```php
     *   $container->parameters['greet'] = 'Hello';
     *   $container->parameters['name'] = 'Divine';
     *
     *   $value = $container->parameter('%greet% how are you %name%');
     *   // Expected value is "Hello how are you Divine"
     * ```
     *
     * @return string or mixed value if $value is supported
     */
    public function parameter(string $value)
    {
        $res = [];
        $parts = \preg_split('#(%[^%\s]+%)#i', $value, -1, \PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts || (3 == \count($parts) && $value === $parts[0])) {
            return $value;
        }

        $partsN = \count($parts = \array_filter($parts));

        foreach ($parts as $part) {
            if ('' !== $part && '%' === $part[0]) {
                $val = \substr($part, 1, -1);

                if (!\array_key_exists($val, $this->parameters)) {
                    throw new \RuntimeException(\sprintf('You have requested a non-existent parameter "%s".', $val));
                }

                $part = $this->parameters[$val];

                if ($partsN > 1 && !\is_scalar($part)) {
                    throw new \InvalidArgumentException(\sprintf('Unable to concatenate non-scalar parameter "%s" into %s.', $val, $value));
                }
            }

            $res[] = $part;
        }

        return [] === $res ? $value : (1 === \count($res) ? $res[0] : \implode('', $res));
    }
}
