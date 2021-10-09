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

/**
 * This trait adds arguments functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ParameterTrait
{
    /** @var array<int|string,mixed> */
    private array $arguments = [];

    /**
     * Sets/Replace one argument to pass to the service constructor/factory method.
     *
     * @param int|string $name
     * @param mixed      $value
     *
     * @return $this
     */
    public function arg($name, $value): self
    {
        $this->arguments[$name] = $value;

        return $this;
    }

    /**
     * Sets the arguments to pass to the service constructor/factory method.
     *
     * @param array<int|string,mixed> $arguments
     *
     * @return $this
     */
    public function args(array $arguments): self
    {
        foreach ($arguments as $name => $value) {
            $this->arguments[$name] = $value;
        }

        return $this;
    }

    /**
     * Whether this definition has constructor/factory arguments.
     */
    public function hasArguments(): bool
    {
        return !empty($this->arguments);
    }

    /**
     * Get the definition's arguments.
     *
     * @return array<int|string,mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }
}
