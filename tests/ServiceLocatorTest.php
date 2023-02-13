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

namespace Rade\DI\Tests;

use Psr\Container\NotFoundExceptionInterface;
use Rade\DI\Container;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Services\ServiceLocator;
use Symfony\Contracts\Service\Test\ServiceLocatorTest as BaseServiceLocatorTest;

/**
 * @group required
 */
class ServiceLocatorTest extends BaseServiceLocatorTest
{
    public function getServiceLocator(array $factories)
    {
        return new ServiceLocator($factories);
    }

    public function testThrowsOnCircularReference(): void
    {
        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessage('Circular reference detected for service "bar", path: "bar -> baz -> bar".');

        parent::testThrowsOnCircularReference();
    }

    public function testThrowsInServiceSubscriber(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage('Service "foo" not found: the current service locator only knows about the "bar" service.');

        $container = new Container();
        $container['foo'] = new \stdClass();
        $subscriber = new Fixtures\SomeServiceSubscriber();
        $subscriber->container = $this->getServiceLocator(['bar' => function (): void {
        }]);

        $subscriber->getFoo();
    }

    public function testGetThrowsServiceNotFoundException(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage('Service "foo" not found: the current service locator is empty...');

        $locator = new ServiceLocator([]);
        $locator->get('foo');
    }

    public function testProvidesServicesInformation(): void
    {
        $locator = new ServiceLocator([
            'foo' => function () {
                return 'bar';
            },
            'bar' => function (): string {
                return 'baz';
            },
            'bat' => function (): ?string {
                return 'zaz';
            },
            'baz' => fn(): \ArrayObject => new \ArrayObject(),
            'null' => null,
        ]);

        $this->assertSame($locator->getProvidedServices(), [
            'foo' => '?',
            'bar' => 'string',
            'bat' => '?string',
            'baz' => 'ArrayObject',
            'null' => '?',
        ]);
    }
}
