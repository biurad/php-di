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
 * This trait adds a few config functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ConfigureTrait
{
    private bool $shared = true;

    private bool $public = true;

    private bool $lazy = false;

    /** @var string|null Expect value to be a valid class|interface|enum|trait type. */
    private ?string $instanceOf = null;

    /**
     * Whether or not the definition should be shareable.
     *
     * @return $this
     */
    final public function shared(bool $boolean = true): self
    {
        $this->shared = $boolean;

        return $this;
    }

    /**
     * Whether or not the definition should be public or private.
     *
     * @return $this
     */
    final public function public(bool $boolean = true): self
    {
        $this->public = $boolean;

        return $this;
    }

    /**
     * Setting lazy is similar to shared, only that definition's
     * entity will be auto resolved by the container.
     *
     * @return $this
     */
    final public function lazy(bool $boolean = true): self
    {
        $this->lazy = $boolean;

        return $this;
    }

    /**
     * If set and service entity not instance of, an excerption will be thrown.
     *
     * @param string $instanceOf Expect value to be a valid class|interface|enum|trait type
     *
     * @return $this
     */
    final public function configure(string $instanceOf): self
    {
        $this->instanceOf = $instanceOf;

        return $this;
    }

    /**
     * Whether this service is public.
     */
    final public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * Whether this service is shared.
     */
    final public function isShared(): bool
    {
        return $this->shared;
    }

    /**
     * Whether this service is lazy.
     */
    final public function isLazy(): bool
    {
        return $this->shared;
    }
}
