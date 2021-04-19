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

use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Exceptions\NotFoundServiceException;

class AppContainer extends Container
{
    protected array $types = [
        ContainerInterface::class => ['container'],
        Container::class => ['container'],
        Definition::class => ['scoped'],
    ];

    protected array $methodsMap = [
        'container' => 'getServiceContainer',
        'scoped'    => 'getDefinition',
        'broken'    => 'getBrokenService',
    ];

    protected function getDefinition(): Definition
    {
        return self:: $services['scoped'] = new Definition('scoped');
    }

    protected function getBrokenService()
    {
        throw new NotFoundServiceException('Broken Service');
    }
}
