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

namespace Rade\DI\Facade;

use PhpParser\BuilderFactory;
use PhpParser\Node\{Stmt\Declare_, Stmt\DeclareDeclare, Stmt\Return_, UnionType};
use Psr\Container\ContainerInterface;
use Rade\DI\Builder\CodePrinter;
use Rade\DI\ContainerBuilder;
use Rade\DI\Resolver;

/**
 * A Proxy manager for implementing laravel like facade system.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class FacadeProxy
{
    private ContainerInterface $container;

    /** @var array<string,string> */
    private array $proxies = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Register(s) service{s) found in container as shared proxy facade(s).
     */
    public function proxy(string ...$services): void
    {
        foreach ($services as $service) {
            $id = \str_replace(['.', '_', '\\'], '', \lcfirst(\ucwords($service, '._')));

            if (!$this->container instanceof ContainerBuilder) {
                Facade::$proxies[$id] = $service;

                continue;
            }

            $this->proxies[$id] = $service;
        }
    }

    /**
     * This build method works with container builder.
     *
     * @param string $className for compiled facade class
     */
    public function build(string $className = 'Facade'): ?string
    {
        /** @var ContainerBuilder */
        $container = $this->container;

        if ([] !== $proxiedServices = $this->proxies) {
            $astNodes = [];
            $builder = $container->getResolver()->getBuilder();
            \ksort($proxiedServices);

            $astNodes[] = new Declare_([new DeclareDeclare('strict_types', $builder->val(1))]);
            $classNode = $builder->class($className)->extend('\Rade\DI\Facade\Facade')->setDocComment(CodePrinter::COMMENT);

            $classNode->addStmts($this->resolveProxies($container, $builder, $proxiedServices));
            $astNodes[] = $classNode->getNode();

            return CodePrinter::print($astNodes);
        }

        return null;
    }

    /**
     * This method resolves the proxies from container builder.
     *
     * @param string[] $proxiedServices
     *
     * @return \PhpParser\Builder\Method[]
     */
    protected function resolveProxies(ContainerBuilder $container, BuilderFactory $builder, array $proxiedServices): array
    {
        $builtProxies = [];

        foreach ($proxiedServices as $method => $proxy) {
            if (!$container->has($proxy)) {
                continue;
            }

            $definition = $container->definition($proxy);
            $proxyNode = $builder->method($method)->makePublic()->makeStatic();

            if (!$definition->isPublic() || $definition->isAbstract()) {
                continue;
            }

            if ($definition->isDeprecated()) {
                $proxyNode->addStmt($builder->funcCall('trigger_deprecation', \array_values($definition->getDeprecation())));
            }

            if ($definition->isTyped()) {
                $types = $definition->getTypes() ?: Resolver::autowireService($definition->getEntity(), true, $container);
                $defTyped = [];

                foreach ($types as $typed) {
                    foreach ($defTyped as $interface) {
                        if (\is_subclass_of($typed, $interface) || \is_subclass_of($interface, $typed)) {
                            continue 2;
                        }
                    }
                    $defTyped[] = $typed;
                }

                if (\count($defTyped) > 1) {
                    $defTyped = [new UnionType(\array_map(fn (string $t) => \PhpParser\BuilderHelpers::normalizeType($t), $defTyped))];
                }
                $proxyNode->setReturnType($defTyped[0] ?? 'mixed');
            }

            $body = $builder->methodCall($builder->staticCall('self', 'container'), 'get', [$proxy]);
            $builtProxies[] = $proxyNode->addStmt(new Return_($body));
        }

        return $builtProxies;
    }
}
