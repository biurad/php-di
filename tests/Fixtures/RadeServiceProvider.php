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

use Rade\DI\AbstractContainer;
use Rade\DI\Extensions\AliasedInterface;
use Rade\DI\Extensions\BootExtensionInterface;
use Rade\DI\Extensions\DependenciesInterface;
use Rade\DI\Extensions\ExtensionInterface;
use Rade\DI\Extensions\PhpExtension;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function Rade\DI\Loader\reference;
use function Rade\DI\Loader\service;
use function Rade\DI\Loader\value;

class RadeServiceProvider implements AliasedInterface, ConfigurationInterface, DependenciesInterface, ExtensionInterface, BootExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'rade_provider';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder($this->getAlias());

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
        $container->getExtensionBuilder()->modifyConfig(OtherServiceProvider::class, ['others' => 'extension']);

        if ($container->hasExtension(ProjectServiceProvider::class)) {
            $container->getExtension(ProjectServiceProvider::class)->addData('hello World');
        }

        $container->set('param', value('value'));
        $container->autowire('service', service(Service::class))->bind('$value', reference('other'));
        $container->autowire('factory', service(Service::class))->shared(false);
    }

    /**
     * {@inheritdoc}
     */
    public function boot(AbstractContainer $builder): void
    {
        if ($builder->hasExtension(PhpExtension::class)) {
            $builder->getExtensionBuilder()->modifyConfig(PhpExtension::class, ['date.timezone' => 'Africa/Ghana']);
        }
        $builder->definition('service')->bind('value', $builder->get('other'));
    }
}
