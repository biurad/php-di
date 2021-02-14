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

class ContainerTaggingTest extends TestCase
{
    public function testContainerTags(): void
    {
        $container = new Container;
        $container->tag(Fixtures\Service::class, ['foo', 'bar']);
        $container->tag(Fixtures\Constructor::class, ['foo']);

        $this->assertCount(1, $container->tagged('bar'));
        $this->assertCount(2, $container->tagged('foo'));

        $fooResults = [];

        foreach ($container->tagged('foo') as [$foo, $attr]) {
            $fooResults[] = $foo;
        }

        $barResults = [];

        foreach ($container->tagged('bar') as [$bar, $attr]) {
            $barResults[] = $bar;
        }

        $this->assertInstanceOf(Fixtures\Service::class, $fooResults[0]);
        $this->assertInstanceOf(Fixtures\Service::class, $barResults[0]);
        $this->assertInstanceOf(Fixtures\Constructor::class, $fooResults[1]);

        $container = new Container;
        $container->tag([Fixtures\Service::class, Fixtures\Constructor::class], ['foo']);
        $this->assertCount(2, $container->tagged('foo'));

        $fooResults = [];

        foreach ($container->tagged('foo') as [$foo, $attr]) {
            $fooResults[] = $foo;
        }

        $this->assertInstanceOf(Fixtures\Service::class, $fooResults[0]);
        $this->assertInstanceOf(Fixtures\Constructor::class, $fooResults[1]);

        $this->assertCount(0, $container->tagged('this_tag_does_not_exist'));
    }

    public function testTaggedServiceByItsAttribute(): void
    {
        $container = new Container;
        $container->tag(Fixtures\Service::class, ['foo' => true]);
        $container->tag(Fixtures\Constructor::class, ['foo' => false]);

        $this->assertCount(2, $container->tagged('foo'));

        $fooResults = [];

        foreach ($container->tagged('foo') as [$foo, $enabled]) {
            if ($enabled) {
                $fooResults[] = $foo;
            }
        }

        $this->assertCount(1, $fooResults);
    }

    public function testTaggedServicesAreLazyLoaded(): void
    {
        $container = $this->createPartialMock(Container::class, ['get']);
        $container->expects($this->exactly(4))->method('get')->willReturn(new Fixtures\Service());

        $container->tag(Fixtures\Service::class, ['foo']);
        $container->tag(Fixtures\Constructor::class, ['foo']);

        $fooResults = [];

        foreach ($container->tagged('foo') as [$foo, $attr]) {
            $fooResults[] = $foo;
            break;
        }

        $this->assertCount(2, $container->tagged('foo'));
        $this->assertInstanceOf(Fixtures\Service::class, $fooResults[0]);
    }

    public function testLazyLoadedTaggedServicesCanBeLoopedOverMultipleTimes(): void
    {
        $container = new Container;
        $container->tag(Fixtures\Service::class, ['foo']);
        $container->tag(Fixtures\Constructor::class, ['foo']);

        $services = $container->tagged('foo');
        $fooResults = [];

        foreach ($services as [$foo, $attr]) {
            $fooResults[] = $foo;
        }

        $this->assertInstanceOf(Fixtures\Service::class, $fooResults[0]);
        $this->assertInstanceOf(Fixtures\Constructor::class, $fooResults[1]);

        $fooResults = [];
        foreach ($services as [$foo, $attr]) {
            $fooResults[] = $foo;
        }

        $this->assertInstanceOf(Fixtures\Service::class, $fooResults[0]);
        $this->assertInstanceOf(Fixtures\Constructor::class, $fooResults[1]);
    }
}
