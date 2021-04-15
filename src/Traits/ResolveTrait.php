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
    private Resolver $resolver;

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
        if (($resolved = $this->entity) instanceof Statement) {
            $resolved = $resolved->value;
            $this->parameters += $resolved->args;
        }

        $resolved = $this->resolver->resolve($resolved, $this->resolveArguments($this->parameters, null, false));

        if (\function_exists('trigger_deprecation') && [] !== $deprecation = $this->deprecated) {
            \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
        }

        foreach ($this->calls as $bind => $value) {
            $arguments = $this->resolveArguments(\is_array($value) ? $value : [$value], null, false);

            if (\is_object($resolved) && !\is_callable($resolved)) {
                if (\property_exists($resolved, $bind)) {
                    $resolved->{$bind} = \is_string($value) ? \current($arguments) : $arguments;

                    continue;
                }

                if (\method_exists($resolved, $bind)) {
                    $this->resolver->resolve([$resolved, $bind], $arguments);

                    continue;
                }
            }

            if (\str_starts_with($bind, '@') && \str_contains($bind, '::')) {
                $this->resolver->resolve(\explode('::', \substr($bind, 1), 2), $arguments);
            }
        }

        return $resolved;
    }
        );
        $node->setDocComment($deprecatedComment);

        return $node;
    }
}
