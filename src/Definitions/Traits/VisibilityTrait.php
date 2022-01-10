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
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\{ArrayDimFetch, Assign};
use PhpParser\Node\Scalar\String_;

/**
 * This trait adds visibility functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait VisibilityTrait
{
    private bool $shared = true;

    private bool $public = true;

    private bool $lazy = false;

    private bool $abstract = false;

    /**
     * {@inheritdoc}
     */
    public function shared(bool $boolean = true)
    {
        $this->shared = $boolean;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function public(bool $boolean = true)
    {
        $this->public = $boolean;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function lazy(bool $boolean = true)
    {
        $this->lazy = $boolean;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function abstract(bool $boolean = true)
    {
        $this->abstract = $boolean;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * {@inheritdoc}
     */
    public function isShared(): bool
    {
        return $this->shared;
    }

    /**
     * {@inheritdoc}
     */
    public function isLazy(): bool
    {
        return $this->lazy;
    }

    /**
     * {@inheritdoc}
     */
    public function isAbstract(): bool
    {
        return $this->abstract;
    }

    /**
     * Triggers for buildable container.
     */
    public function triggerSharedBuild(string $id, Expr $service, BuilderFactory $builder): Assign
    {
        $serviceVar = new ArrayDimFetch(
            $builder->propertyFetch($builder->var('this'), $this->public ? 'services' : 'privates'),
            new String_($id)
        );

        return new Assign($serviceVar, $service);
    }
}
