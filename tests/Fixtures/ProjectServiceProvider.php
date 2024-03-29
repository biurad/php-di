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
use Rade\DI\Container;
use Rade\DI\Extensions\ExtensionInterface;

use function Rade\DI\Loader\service;

class ProjectServiceProvider implements ExtensionInterface
{
    private array $values = [];

    public function addData(string $value): void
    {
        $this->values[] = $value;
    }
    /**
     * {@inheritdoc}
     */
    public function register(AbstractContainer $container, array $configs = []): void
    {
        $container->parameters['project.configs'] = $configs + ['values' => $this->values];
        $config = \array_filter($configs);

        if ($container instanceof Container) {
            $entity = $container->definition('FooClass');
        }

        $container->set('project.service.bar', service($entity ?? FooClass::class));
        $container->parameters['project.parameter.bar'] = $config['foo'] ?? 'foobar';

        $container->set('project.service.foo', service($entity ?? FooClass::class));
        $container->parameters['project.parameter.foo'] = $config['foo'] ?? 'foobar';
    }
}
