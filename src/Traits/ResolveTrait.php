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

use Nette\Utils\{Callback, Reflection};
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\{
    Expr,
    Expr\Array_,
    Expr\ArrayItem,
    Expr\Assign,
    Expr\New_,
    Expr\Variable,
    Scalar\String_
};
use PhpParser\ParserFactory;
use Rade\DI\{
    Builder\Statement,
    Exceptions\ServiceCreationException,
    RawDefinition,
    Builder\Reference,
    Definition,
    Resolvers\Resolver
};
use Rade\DI\Exceptions\ContainerResolutionException;

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

    private bool $autowire = false;

    /**
     * Resolves the Definition when in use in Container.
     */
    public function __invoke()
    {
        if (($resolved = $this->entity) instanceof Statement) {
            $resolved = $resolved->value;
            $this->parameters += $resolved->args;
        }

        try {
            $resolved = $this->resolver->resolve($resolved, $this->resolveArguments($this->parameters, null, false));
        } catch (ContainerResolutionException $e) {
            // May be a valid object class
            if (!\is_object($resolved)) {
                throw $e;
            }
        }

        if (\function_exists('trigger_deprecation') && [] !== $deprecation = $this->deprecated) {
            \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
        }

        foreach ($this->calls as $bind => $value) {
            $arguments = $this->resolveArguments(\is_array($value) ? $value : [$value], null, false);

            if (\is_object($resolved) && !\is_callable($resolved)) {
                if (\property_exists($resolved, $bind)) {
                    $resolved->{$bind} = !\is_array($value) ? \current($arguments) : $arguments;

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

    protected function resolveReference(Reference $reference, bool $callback = false)
    {
        if ('[]' === \substr($referenced = (string) $reference, -2)) {
            $referenced = \substr($referenced, 0, -2);

            if ($callback) {
                throw new ServiceCreationException(
                    \sprintf('Using a array like service %s reference for callable service is not supported.', $referenced)
                );
            }

            if ($this->resolver->has($referenced)) {
                return $this->resolver->get($referenced);
            }

            return [$this->resolver->getContainer()->get($referenced)];
        }

        return $this->resolver->getContainer()->get($referenced);
    }
        );
        $node->setDocComment($deprecatedComment);

        return $node;
    }
}
