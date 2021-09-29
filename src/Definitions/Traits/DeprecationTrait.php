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

use PhpParser\BuilderFactory;

/**
 * This trait adds deprecation functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait DeprecationTrait
{
    /** @var array<string,string> */
    private array $deprecation = [];

    /**
     * Whether this definition is deprecated, that means it should not be used anymore.
     *
     * @param string      $package The name of the composer package that is triggering the deprecation
     * @param float|null  $version The version of the package that introduced the deprecation
     * @param string|null $message The deprecation message to use
     *
     * @return $this
     */
    public function deprecate(string $package = '', float $version = null, string $message = null): self
    {
        $this->deprecation['package'] = $package;
        $this->deprecation['version'] = $version ?? '';

        if (!empty($message)) {
            if (\preg_match('#[\r\n]|\*/#', $message)) {
                throw new \InvalidArgumentException('Invalid characters found in deprecation template.');
            }

            if (!\str_contains($message, '%service_id%')) {
                throw new \InvalidArgumentException('The deprecation template must contain the "%service_id%" placeholder.');
            }
        }

        $this->deprecation['message'] = $message ?? 'The "%service_id%" service is deprecated. avoid using it, as it will be removed in the future.';

        return $this;
    }

    /**
     * Whether this definition is deprecated, that means it should not be called anymore.
     */
    public function isDeprecated(): bool
    {
        return !empty($this->deprecation);
    }

    /**
     * Return a non-empty array if definition is deprecated.
     *
     * @param string $id Service id relying on this definition
     *
     * @return array<string,string>
     */
    public function getDeprecation(string $id): array
    {
        if (isset($this->deprecation['message'])) {
            $this->deprecation['message'] = \str_replace('%service_id%', $id, $this->deprecation['message']);
        }

        return $this->deprecation;
    }

    /**
     * Triggers a silenced deprecation notice.
     *
     * @param string $id
     * @param BuilderFactory|null $builder
     *
     * @return \PhpParser\Node\Expr\FuncCall|null
     */
    public function triggerDeprecation(string $id, ?BuilderFactory $builder = null)
    {
        if ([] !== $deprecation = $this->getDeprecation($id)) {
            \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);

            if (null !== $builder) {
                return $builder->funcCall('\trigger_deprecation', \array_values($deprecation));
            }
        }

        return null;
    }
}
