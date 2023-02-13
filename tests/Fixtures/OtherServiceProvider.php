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
use Rade\DI\AbstractContainer;
use Rade\DI\Container;
use Rade\DI\Extensions\ExtensionInterface;
use Rade\DI\Extensions\PhpExtension;
use Rade\DI\Extensions\RequiredPackagesInterface;

class OtherServiceProvider implements ExtensionInterface, RequiredPackagesInterface
{
    /**
     * {@inheritdoc}
     */
    public function getRequiredPackages(): array
    {
        return [Container::class => 'divineniiquaye/rade-di'];
    }

    /**
     * {@inheritdoc}
     */
    public function register(AbstractContainer $container, array $configs = []): void
    {
        $container->parameters['other'] = $configs;
        $container->alias('other', ContainerInterface::class);

        Assert::assertCount(4, $container->getExtensions());
        Assert::assertEquals(\date_default_timezone_get(), $container->getExtensionConfig(PhpExtension::class)['date.timezone']);
    }
}
