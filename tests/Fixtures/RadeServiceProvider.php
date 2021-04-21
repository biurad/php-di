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
use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\Services\AbstractConfiguration;
use Rade\DI\Services\ServiceProviderInterface;
use Rade\DI\Services\DependedInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class RadeServiceProvider extends AbstractConfiguration implements ConfigurationInterface, DependedInterface, ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return 'rade_di';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder($this->getId());

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('hello')->end()
            ->end()
        ;

        return $treeBuilder;
    }

    public function setConfiguration(array $config, ContainerInterface $container): void
    {
        try {
            $this->getConfiguration();
        } catch (\RuntimeException $e) {
            Assert::assertEquals(
                'Configurations for this provider is empty. See \'setConfiguration\' method.',
                $e->getMessage()
            );
        }
        $this->config = $config;

        if ($container instanceof Container) {
            $container->parameters[$this->getId()] = $this->getConfiguration();
        }
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
    public function register(Container $rade): void
    {
        $rade['param'] = $rade->raw('value');

        $rade['service'] = function () use ($rade) {
            $service = new Service();
            $service->value = $rade['other'];

            return $service;
        };

        $rade['factory'] = $rade->factory(function () {
            return new Service();
        });
    }
}
