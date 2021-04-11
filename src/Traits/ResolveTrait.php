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

use Rade\DI\{
    Builder\Statement,
    ContainerBuilder,
    Exceptions\ContainerResolutionException,
    RawService,
    Builder\Reference,
    Resolvers\Resolver
};

/**
 * Resolves entity, parameters and calls in definition tree.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ResolveTrait
{
    public ?Resolver $resolver = null;

    /** @var mixed */
    private $entity;

    private array $parameters;

    /** @var string[]|string|null */
    private $type = null;

    private array $calls = [];

    /**
     * Resolves the Definition
     *
     * @throws \ReflectionException
     */
    public function __invoke()
    {
        $resolved = $this->entity;

        if ($resolved instanceof RawService) {
            throw new ContainerResolutionException('Not supported service entity as it\'s an instance of RawService');
        }

        if (\is_callable($resolved)) {
            return $this->resolver->resolve($resolved, $this->parameters);
        }

        if (\is_string($resolved) && \class_exists($resolved)) {
            $resolved = $this->resolver->resolveClass($resolved, $this->parameters);
        }

        if ([] !== $this->calls && \is_object($resolved)) {
            foreach ($this->calls as $bind => $value) {
                if (\property_exists($resolved, $bind)) {
                    $resolved->{$bind} = $value;

                    continue;
                }

                if (\method_exists($resolved, $bind)) {
                    $this->resolver->resolve([$resolved, $bind], !\is_array($value) ? [$value] : $value);
                }
            }
        }

        return $resolved;
    }
        );
        $node->setDocComment($deprecatedComment);

        return $node;
    }
}
