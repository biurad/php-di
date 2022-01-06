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

/**
 * This interface controls the visibility of a service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ShareableDefinitionInterface
{
    /**
     * Whether or not the definition should be shareable.
     *
     * @return $this
     */
    public function shared(bool $boolean = true);

    /**
     * Whether or not the definition should be public or private.
     *
     * @return $this
     */
    public function public(bool $boolean = true);

    /**
     * If true, service will only be resolved when called.
     *
     * @return $this
     */
    public function lazy(bool $boolean = true);

    /**
     * If true, service becomes reusable as a parent child definition.
     *
     * @return $this
     */
    public function abstract(bool $boolean = true);

    /**
     * Whether this service is public.
     */
    public function isPublic(): bool;

    /**
     * Whether this service is shared.
     */
    public function isShared(): bool;

    /**
     * Whether this service is lazy.
     */
    public function isLazy(): bool;

    /**
     * Whether this service is abstract.
     */
    public function isAbstract(): bool;
}
