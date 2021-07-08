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

use PHPUnit\Framework\Assert;
use Rade\DI\AbstractContainer;
use Rade\DI\Builder\PrependInterface;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Definition;
use Rade\DI\Services\ServiceProviderInterface;
use Rade\DI\Services\DependedInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class RadeServiceProvider implements ConfigurationInterface, DependedInterface, ServiceProviderInterface, PrependInterface
{
    public static function getId(): string
    {
        return 'rade_provider';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::getId());

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('hello')->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function dependencies(): array
    {
        return [OtherServiceProvider::class];
    }

    /**
     * {@inheritdoc}
     */
    public function register(AbstractContainer $container, array $configs = []): void
    {
        $container->parameters['rade_di'] = $configs;

        if ($container instanceof Container) {
            $container['param'] = $container->raw('value');
            $container['factory'] = $container->definition(Service::class, Definition::FACTORY);
            $container['service'] = function () use ($container) {
                $service = new Service();
                $service->value = $container['other'];

                return $service;
            };
        } elseif ($container instanceof ContainerBuilder) {
            $container->set('param', $container->raw('value'));
            $container->autowire('service', Service::class);
            $container->autowire('factory', Service::class)->should(Definition::FACTORY);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function before(ContainerBuilder $builder): void
    {
        $builder->extend('service')->bind('value', $builder->get('other'));
    }
}
