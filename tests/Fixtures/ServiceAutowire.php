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

namespace Rade\DI\Tests\Fixtures;

use DivineNii\Invoker\ArgumentResolver\NamedValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;

/**
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ServiceAutowire
{
    public Service $value;

    public ?Invokable $invoke;

    public function __construct(Service $service, ?Invokable $invoke)
    {
        $this->invoke = $invoke;
        $this->value = $service;
    }

    public function missingClass(Servic $service)
    {
        return $service;
    }

    public function missingService(Service $service)
    {
        return $service;
    }

    public function multipleAutowireTypes(ArgumentValueResolverInterface $resolver)
    {
        return $resolver;
    }

    /**
     * @param NamedValueResolver $resolver
     *
     * @return ArgumentValueResolverInterface
     */
    public function multipleAutowireTypesFound(ArgumentValueResolverInterface $resolver)
    {
        return $resolver;
    }

    /**
     * @return ArgumentValueResolverInterface
     */
    public function multipleAutowireTypesNotFound(ArgumentValueResolverInterface $resolver)
    {
        return $resolver;
    }

    /**
     * @param ArgumentValueResolverInterface[] $resolvers
     *
     * @return ArgumentValueResolverInterface[]
     */
    public function autowireTypesArray(array $resolvers): array
    {
        return $resolvers;
    }
}
