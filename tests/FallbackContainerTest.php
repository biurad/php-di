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
use Rade\DI\ContextContainer;
use Rade\DI\Definition;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Tests\Fixtures\AppContainer;

use function Rade\DI\Loader\value;

/**
 * @group required
 */
class FallbackContainerTest extends TestCase
{
    public function testContextContainerSequence(): void
    {
        $context = new ContextContainer();

        $rade1 = new Container();
        $rade1->set('foo', value('Hello'));
        $context->attach($rade1);

        $rade2 = new Container();
        $rade2->set('bar', value('Hello'));
        $context->attach($rade2);

        $this->assertEquals($context->get('foo'), $context->get('bar'));
        $this->expectExceptionMessage('Requested service "foo" was not found in any container. Did you forget to set it?');
        $this->expectException(NotFoundServiceException::class);

        $context->detach($rade1);
        $context->get('foo');
    }

    public function testContextContainersNameConflict(): void
    {
        $context = new ContextContainer();

        $rade1 = new Container();
        $rade1->set(AppContainer::class, value('Hello'));
        $context->attach($rade1);

        $rade2 = new AppContainer();
        $context->attach($rade2);

        $this->assertEquals('Hello', $context->get(AppContainer::class));

        $context->reset();

        $rade3 = new AppContainer();
        $context->attach($rade3);

        $rade4 = new Container();
        $rade4->set(AppContainer::class, value('Hello'));
        $context->attach($rade4);

        $this->assertSame($rade3, $context->get(AppContainer::class));
    }

    public function testFallbackContainerErrors(): void
    {
        $rade = new AppContainer();
        $rade->autowire('service', new Definition(Fixtures\Service::class));
        $rade->autowire(Fixtures\Constructor::class);

        $context = new ContextContainer();
        $context->attach($rade);

        try {
            $context->get('throws_exception_on_service_configuration');
        } catch (\Exception $e) {
            $this->assertEquals('Something was terribly wrong while trying to configure the service!', $e->getMessage());
        }

        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Requested service "nothing" was not found in any container. Did you forget to set it?');

        $context->get('nothing');
    }

    public function testServicesFallback(): void
    {
        $rade1 = new Container();
        $rade1->set('foo', new Fixtures\Constructor($rade1));
        $rade1->type('foo', Fixtures\Constructor::class);

        $rade2 = new Container();
        $rade2->set('bar', new Fixtures\Service());
        $rade2->type('bar', Fixtures\Service::class);

        $rade = new ContextContainer();
        $rade->attach($rade1);
        $rade->attach($rade2);
        $rade->attach(new AppContainer());

        $this->assertInstanceOf(Fixtures\Service::class, $foo = $rade->get(Fixtures\Constructor::class));
        $this->assertNotSame($foo, $bar = $rade->get(Fixtures\Service::class));

        $rade2->removeType(Fixtures\Service::class);

        $this->assertSame($foo, $rade->get('foo'));
        $this->assertTrue($rade->has('bar'));
        $this->assertFalse($rade2->typed(Fixtures\Service::class));
        $this->assertNotSame($bar, $rade2->get(Fixtures\Service::class));
        $this->assertSame($rade->get('foo_baz'), $rade->get(Fixtures\BarInterface::class));
    }

    public function testNotFoundService(): void
    {
        $container = new AppContainer();
        $container->autowire('foo_bar_baz', new Fixtures\Bar('Hi Rade DI'));

        $rade = new ContextContainer();
        $rade->attach($container);

        try {
            $rade->get(Fixtures\BarInterface::class);
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('Requested service "Rade\DI\Tests\Fixtures\BarInterface" was not found in any container. Did you forget to set it?', $e->getMessage());
        }

        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Requested service "Rade\DI\Tests\Fixtures\ConstructNotExists" was not found in any container. Did you forget to set it?');

        $rade->get(Fixtures\ConstructNotExists::class);
    }

    public function testFallbackContainer(): void
    {
        $fallback = new AppContainer();

        $rade = new ContextContainer();
        $rade->attach($fallback);

        $this->assertFalse($rade->has(AppContainer::class));
        $this->assertSame($fallback, $rade->get(AppContainer::class));
        $this->assertSame($rade, $rade->get(ContextContainer::class));
        $this->assertSame($fallback, $rade->get(Container::class));
        $this->assertSame($rade, $rade->get(ContainerInterface::class));

        $this->assertSame($fallback->get('bar'), $rade->get('alias'));
    }
}
