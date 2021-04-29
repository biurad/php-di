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

use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rade\DI\AbstractContainer;
use Rade\DI\ContainerBuilder;
use Rade\DI\Builder\Reference;
use Rade\DI\Builder\Statement;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\NotFoundServiceException;

use function Composer\Autoload\includeFile;

class ContainerBuilderTest extends TestCase
{
    private const COMPILED = __DIR__ . '/Fixtures/compiled';

    public function testContainer(): void
    {
        $builder = new ContainerBuilder();

        $this->assertInstanceOf(Variable::class, $builder->get(Container::class));
        $this->assertInstanceOf(Variable::class, $builder->get(ContainerInterface::class));
        $this->assertInstanceOf(Variable::class, $builder->get(AbstractContainer::class));

        $this->expectExceptionMessage('Identifier "container" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $builder->get('container');
    }

    public function testRawDefinition(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('raw', $builder->raw(123));

        $this->assertInstanceOf(LNumber::class, $builder->get('raw'));

        $this->assertEquals(
            \file_get_contents($path = self::COMPILED . '/service2.phpt'),
            $builder->compile(['containerClass' => 'EmptyContainer'])
        );

        includeFile($path);

        $container = new \EmptyContainer();

        $this->expectExceptionMessage('Identifier "raw" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container->get('raw');
    }

    public function testDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class);
        $builder->autowire('autowired', Fixtures\Service::class);
        $builder->set('statement', new Statement(Fixtures\Constructor::class, [new Reference(Container::class)]));

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('autowired'));
        $this->assertInstanceOf(Coalesce::class, $builder->get(Fixtures\Service::class));
        $this->assertInstanceOf(Coalesce::class, $builder->get('statement'));

        $this->assertEquals(\file_get_contents($path = self::COMPILED . '/service1.phpt'), $builder->compile());

        includeFile($path);

        $container = new \CompiledContainer();

        $this->assertInstanceOf(Fixtures\Service::class, $container->get('service_1'));
        $this->assertInstanceOf(Fixtures\Constructor::class, $container->get('statement'));

        $this->assertInstanceOf(Fixtures\Service::class, $service1 = $container->get('autowired'));
        $this->assertInstanceOf(Fixtures\Service::class, $service2 = $container->get(Fixtures\Service::class));
        $this->assertSame($service1, $service2);
    }

    public function testFactoryDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class)->should();
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::FACTORY | Definition::LAZY);

        $this->assertEquals(
            \file_get_contents($path = self::COMPILED . '/service4.phpt'),
            $builder->compile(['containerClass' => 'FactoryContainer'])
        );

        includeFile($path);

        $container = new \FactoryContainer();
        $this->assertNotSame($container->get('service_1'), $container->get('service_2'));
        $this->assertNotSame($container->get('service_1'), $container->get('service_1'));
        $this->assertNotSame($container->get('service_2'), $container->get('service_2'));
    }

    public function testPrivateDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class)->should(Definition::PRIVATE);
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::PRIVATE | Definition::LAZY);
        $builder->set('service_3', Fixtures\Service::class)->should(Definition::FACTORY | Definition::PRIVATE);

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('service_2'));
        $this->assertInstanceOf(MethodCall::class, $builder->get('service_3'));

        $this->assertEquals(
            \file_get_contents($path = self::COMPILED . '/service5.phpt'),
            $builder->compile(['containerClass' => 'PrivateContainer'])
        );

        includeFile($path);

        $container = new \PrivateContainer();

        $this->assertFalse($container->has('service_1'));
        $this->assertFalse($container->has('service_2'));

        $this->expectExceptionMessage('Identifier "service_3" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container->get('service_3');
    }

    public function testLazyDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_test', Fixtures\Constructor::class)->bind('value', new Reference('service_4'));
        $builder->set('service_1', Fixtures\Service::class)->should(Definition::LAZY);
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::LAZY | Definition::FACTORY);
        $builder->set('service_3', Fixtures\Service::class)->should(Definition::LAZY | Definition::PRIVATE);
        $builder->autowire('service_4', Fixtures\Service::class)->should(Definition::LAZY | Definition::PRIVATE | Definition::FACTORY);

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(MethodCall::class, $builder->get('service_2'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('service_3'));
        $this->assertInstanceOf(MethodCall::class, $service1 = $builder->get('service_4'));
        $this->assertSame($service1, $builder->get(Fixtures\Service::class));

        $this->assertEquals(
            \file_get_contents($path = self::COMPILED . '/service3.phpt'),
            $builder->compile(['containerClass' => 'LazyContainer'])
        );

        includeFile($path);

        $container = new \LazyContainer();

        $this->assertNotSame($service = $container->get('service_test'), $same = $container->get('service_1'));
        $this->assertNotSame($service, $container->get('service_2'));
        $this->assertSame($same, $container->get('service_1'));

        $this->expectExceptionMessage('Identifier "service_3" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container->get('service_3');
    }

    public function testFluentRegister(): void
    {
        $builder = new ContainerBuilder();
        $builder->register(new Fixtures\RadeServiceProvider(), ['hello' => 'Divine']);

        $this->assertTrue(isset($builder->parameters['rade_di']['hello']));
        $this->assertCount(3, $builder->keys());

        $value = \Closure::bind(static function (Definition $definition) {
            return $definition->calls['value'] ?? null;
        }, null, Definition::class);
        $this->assertNull($value($builder->extend('service')));

        $builder->compile();

        $this->assertInstanceOf(Variable::class, $container = $value($builder->extend('service')));
        $this->assertEquals('this', $container->name);
    }

    public function testIndirectCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('c')]);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->compile();
    }

    public function testIndirectDeepCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', [new Reference('c'), 'getInstance']);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->compile();
    }

    public function testDeepCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('c')]);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);

        $this->expectExceptionMessage('Circular reference detected for service "b", path: "a -> b -> c -> b".');
        $this->expectException(CircularReferenceException::class);

        $builder->compile();
    }

    public function testCircularReferenceWithCallableAlike(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', [new Reference('b'), 'getInstance']);
        $builder->set('b', [new Reference('a'), 'getInstance']);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->compile();
    }

    public function testCircularReferenceChecksMethodsCalls(): void
    {
        $builder = new ContainerBuilder();

        $builder->autowire('a', Fixtures\Constructor::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->bind('missingService', new Reference('a'));

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->compile();
    }

    public function testCircularReferenceChecksLazyServices(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')])->should(Definition::LAZY);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        // Unless no arguments are provided, circular referencing is ignored
        $builder->compile();
    }
}
