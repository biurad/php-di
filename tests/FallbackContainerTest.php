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
use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\FallbackContainer;
use Rade\DI\Tests\Fixtures\AppContainer;

class FallbackContainerTest extends TestCase
{
    public function testFallbackContainerNameConflict(): void
    {
        $rade = new FallbackContainer();

        // Service before fallback
        $rade[AppContainer::class] = $rade->raw('something');
        $rade->fallback($fallback = new AppContainer());

        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
        $this->assertSame($fallback, $rade[AppContainer::class]);

        // Unset to check if fallback will still exist.
        unset($rade[AppContainer::class]);

        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);

        $rade->reset();

        // Fallback before service
        $rade->fallback($fallback = new AppContainer());
        $rade[AppContainer::class] = $rade->raw('something');

        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
        unset($rade[AppContainer::class]);

        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
    }

    public function testFallbackContainerErrors(): void
    {
        $rade = new FallbackContainer();
        $rade->fallback(new AppContainer());

        try {
            $rade->get('broken');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('Identifier "broken" is not defined.', $e->getMessage());
        }

        $this->expectExceptionObject(new ContainerResolutionException('Service id \'nothing\' is not found in container'));
        $rade->alias('oops', 'nothing');

        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "nothing" is not defined.');

        $rade->has('nothing');
    }

    public function testServicesFallback(): void
    {
        $fallback = new Container();
        $fallback->set('foo', new Fixtures\Constructor($fallback));
        $fallback->type('foo', Fixtures\Constructor::class);

        $rade = new FallbackContainer();
        $rade->fallback($fallback);
        $rade->set('bar', new Fixtures\Service());
        $rade->type('bar', Fixtures\Service::class);

        $this->assertInstanceOf(Fixtures\Service::class, $foo = $rade->get(Fixtures\Constructor::class));
        $this->assertNotSame($foo, $bar = $rade->get(Fixtures\Service::class));

        $rade->remove(Fixtures\Service::class);

        $this->assertSame($foo, $rade->get('foo'));
        $this->assertTrue($rade->has('bar'));
        $this->assertFalse($rade->typed(Fixtures\Service::class));
        $this->assertNotSame($bar, $rade->get(Fixtures\Service::class));
    }

    public function testNotFoundService(): void
    {
        $rade = new FallbackContainer();
        $this->assertInstanceOf(Fixtures\Service::class, $rade->get(Fixtures\Service::class));

        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "Rade\DI\Tests\Fixtures\ConstructNotExists" is not defined.');

        $rade->get(Fixtures\ConstructNotExists::class);
    }

    public function testFallbackContainer(): void
    {
        $fallback = new AppContainer();
        $rade = new FallbackContainer();
        $rade->fallback($fallback);

        $this->assertTrue(isset($rade[AppContainer::class]));
        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertSame($fallback, $rade->get(AppContainer::class));
        $this->assertSame($rade, $rade->get(FallbackContainer::class));
        $this->assertSame($rade, $rade->get(Container::class));
        $this->assertSame($rade, $rade->get(ContainerInterface::class));

        $rade->alias('aliased', 'scoped');
        $this->assertSame($fallback->get('scoped'), $rade->get('aliased'));
    }

    public function testThatAFallbackContainerSupportAutowiring(): void
    {
        $fallback = new AppContainer();
        $rade = new FallbackContainer();
        $rade->fallback($fallback);
        $rade['t_call'] = fn (AppContainer $app) => $app['scoped'];

        $this->assertInstanceOf(Definition::class, $one = $rade['scoped']);
        $this->assertSame($one, $rade['t_call']);
        $this->assertSame($rade, $rade->get(ContainerInterface::class));
        $this->assertNotSame($rade[AppContainer::class], $rade->get(ContainerInterface::class));

        $this->assertTrue(isset($rade['scoped']));
        $this->assertInstanceOf(Definition::class, $def = $rade['scoped']);
        $this->assertSame($def, $rade->get(Definition::class));

        $this->assertInstanceOf(Definition::class, $rade->call(fn (Definition $def) => $def));
        $this->assertSame($def, $rade->call(fn (Definition $def) => $def));
        $this->assertSame($fallback, $rade->call(fn (AppContainer $app) => $app));
    }
}
