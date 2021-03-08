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

use Rade\DI\Container;
use Rade\DI\ServiceProviderInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class RadeServiceProvider implements ConfigurationInterface, ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'rade_di';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder($this->getName());

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('hello')->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Registers services on the given container.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Container $rade An Container instance
     */
    public function register(Container $rade): void
    {
        $rade['param'] = $rade->raw('value');

        $rade['service'] = function () {
            return new Service();
        };

        $rade['factory'] = $rade->factory(function () {
            return new Service();
        });
    }
}
