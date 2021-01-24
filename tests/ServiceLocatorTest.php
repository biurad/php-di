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

use PHPUnit\Framework\TestCase;
use Rade\DI\Container;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\ServiceLocator;

/**
 * ServiceLocator test case.
 *
 * @author Pascal Luna <skalpa@zetareticuli.org>
 */
class ServiceLocatorTest extends TestCase
{
    public function testCanAccessServices()
    {
        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['service']);

        $this->assertSame($rade['service'], $locator->get('service'));
    }

    public function testCanAccessAliasedServices()
    {
        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['alias' => 'service']);

        $this->assertSame($rade['service'], $locator->get('alias'));
    }

    public function testCannotAccessAliasedServiceUsingRealIdentifier()
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "service" is not defined.');

        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['alias' => 'service']);

        $service = $locator->get('service');
    }

    public function testGetValidatesServiceCanBeLocated()
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "foo" is not defined.');

        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['alias' => 'service']);

        $service = $locator->get('foo');
    }

    public function testGetValidatesTargetServiceExists()
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "invalid" is not defined.');

        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['alias' => 'invalid']);

        $service = $locator->get('alias');
    }

    public function testHasValidatesServiceCanBeLocated()
    {
        $rade = new Container();
        $rade['service1'] = function () {
            return new Fixtures\Service();
        };
        $rade['service2'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['service1']);

        $this->assertTrue($locator->has('service1'));
        $this->assertFalse($locator->has('service2'));
    }

    public function testHasChecksIfTargetServiceExists()
    {
        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $locator = new ServiceLocator($rade, ['foo' => 'service', 'bar' => 'invalid']);

        $this->assertTrue($locator->has('foo'));
        $this->assertFalse($locator->has('bar'));
    }
}
