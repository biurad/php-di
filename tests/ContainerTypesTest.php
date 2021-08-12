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
use Rade\DI\Exceptions\ContainerResolutionException;

class ContainerTypesTest extends TestCase
{
    public function testIsTyped(): void
    {
        $rade = new Container();
        $rade->set('foo', new Fixtures\Constructor($rade));
        $rade->type('foo', Fixtures\Service::class);

        $this->assertTrue($rade->typed(Fixtures\Service::class));
        $this->assertEquals(['foo'], $rade->typed(Fixtures\Service::class, true));
    }

    public function testTypes(): void
    {
        $rade = new Container();
        $rade->set('foo', new Fixtures\Constructor($rade));
        $rade->set('bar', new Fixtures\Service());
        $rade->types(['foo' => [Fixtures\Constructor::class, Fixtures\Service::class], 'bar' => Fixtures\Service::class]);

        $this->assertTrue($rade->typed(Fixtures\Constructor::class));
        $this->assertEquals(['foo', 'bar'], $rade->typed(Fixtures\Service::class, true));

        $this->expectExceptionObject(new ContainerResolutionException('Service identifier is not defined, integer found.'));
        $rade->types(['foo']);
    }

    public function testTypeNotFound(): void
    {
        $this->expectExceptionMessage('Service of type \'foo\' not found. Check class name because it cannot be found.');
        $this->expectException('Rade\DI\Exceptions\NotFoundServiceException');

        $rade = new Container();
        $rade->autowired('foo');
    }

    public function testAutowiringSingle(): void
    {
        $rade = new Container();
        $rade->set('foo', new Fixtures\Constructor($rade));
        $rade->set('bar', $bar = new Fixtures\Service());
        $rade->type('foo', Fixtures\Service::class);

        $this->assertNotSame($bar, $rade->autowired(Fixtures\Service::class));
    }

    public function testAutowiringSingleWithError(): void
    {
        $rade = new Container();
        $rade->set('foo', new Fixtures\Constructor($rade));
        $rade->set('bar', new Fixtures\Service());
        $rade->types(['foo' => [Fixtures\Constructor::class, Fixtures\Service::class], 'bar' => Fixtures\Service::class]);

        $this->assertEquals(['foo', 'bar'], $rade->typed(Fixtures\Service::class, true));

        $this->expectExceptionObject(new ContainerResolutionException('Multiple services of type Rade\DI\Tests\Fixtures\Service found: bar, foo'));
        $rade->autowired(Fixtures\Service::class, true);
    }
}
