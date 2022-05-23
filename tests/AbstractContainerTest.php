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

use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\{FuncCall, MethodCall, New_, PropertyFetch, StaticCall};
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Rade\DI\{AbstractContainer, Container, ContainerBuilder, ContainerInterface, Definition, DefinitionBuilder};
use Rade\DI\Builder\PhpLiteral;
use Rade\DI\Definitions\{DefinitionInterface, Reference, Statement, ValueDefinition};
use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException, FrozenServiceException, NotFoundServiceException, ServiceCreationException};
use Rade\DI\Extensions\ExtensionBuilder;
use Rade\DI\Extensions\PhpExtension;

use function Rade\DI\Loader\param;
use function Rade\DI\Loader\phpCode;
use function Rade\DI\Loader\reference;
use function Rade\DI\Loader\service;
use function Rade\DI\Loader\wrap;

abstract class AbstractContainerTest extends TestCase
{
    abstract public function getContainer(): AbstractContainer;

    abstract public function testValueDefinition(): void;

    abstract public function testPrivateDefinition(): void;

    public function testContainer()
    {
        $container = $this->getContainer();

        try {
            $container->set(AbstractContainer::SERVICE_CONTAINER, new Fixtures\Service());
            $this->fail('A frozen exception is expected to be thrown as service id is initialized');
        } catch (FrozenServiceException $e) {
            $this->assertEquals($e->getMessage(), \sprintf('The "%s" service is already initialized, and cannot be replaced.', AbstractContainer::SERVICE_CONTAINER));
        }

        return $container;
    }

    public function testContainerIsCloneable(): void
    {
        $this->expectExceptionMessage('Container is not cloneable');
        $this->expectException(\LogicException::class);

        $cloned = clone $this->getContainer();
    }

    public function testDefinition(): void
    {
        $container = $this->getContainer();
        $definitions = [
            \stdClass::class,
            'foo' => new Definition('Bar\FooClass'),
            'bar' => new Definition('BarClass'),
            'value' => new ValueDefinition(['a' => 'b', 'c' => 'd']),
            'service' => new Statement('phpinfo', ['what' => \INFO_ALL]),
        ];
        $container->multiple($definitions);

        $this->assertEquals([\stdClass::class, 'foo', 'bar', 'value', 'service'], \array_keys($container->definitions()));
        $this->assertTrue($container->has('foo'));
        $this->assertTrue($container->has('bar'));
        $this->assertFalse($container->has('foobar'));
        $this->assertTrue($container->has(\stdClass::class));

        $this->assertInstanceOf(Definition::class, $container->definition('foo'));
        $this->assertInstanceOf(Definition::class, $container->definition('bar'));
        $this->assertInstanceOf(ValueDefinition::class, $container->definition('value'));
        $this->assertInstanceOf(Definition::class, $container->definition(\stdClass::class));
        $this->assertInstanceOf(Definition::class, $container->definition('service'));
        $this->assertTrue($container->definition('service')->hasArguments());
        $this->assertEquals(['what' => \INFO_ALL], $container->definition('service')->getArguments());

        $container->set('Bar\FooClass');
        $this->assertInstanceOf(Definition::class, $container->definition('Bar\FooClass'));

        $container->set('foobar', $foo = new Definition('FooBarClass'));
        $this->assertEquals($foo, $container->definition('foobar'));
        $this->assertSame($container->set('foobar', $foo = new Definition('FooBarClass')), $foo);

        $container->set('foobar', new Definition('FooBarClass'));
        $this->assertCount(7, $container->definitions());

        $container->set('inline_callback', new Statement(['a' => 'b'], [], true));
        $inline = $container->definition('inline_callback');
        $this->assertTrue($inline instanceof \Closure || $inline instanceof \PhpParser\Node\Expr\ArrowFunction);

        $container->set('closure', fn () => fn () => 'foobar');

        if ($container instanceof Container) {
            $this->assertIsCallable($container->get('closure'));
            $this->assertIsArray($container->get('gettimeofday'));
        } else {
            try {
                $container->get('closure');
                $this->fail('->set() throws a ServiceCreationException if the definition entity return is a closure');
            } catch (ServiceCreationException $e) {
                $this->assertEquals('Cannot dump closure for service "closure".', $e->getMessage());
            }
            $this->assertInstanceOf(FuncCall::class, $container->get('gettimeofday'));
        }

        try {
            $container->definition('foo')->replace(new Definition(Fixtures\Service::class), true);
            $this->fail('->replace() expects to fail as it cannot self contain a self like type');
        } catch (ServiceCreationException $e) {
            $this->assertEquals(\sprintf('A definition entity must not be an instance of "%s".', DefinitionInterface::class), $e->getMessage());
        }

        try {
            $container->get('baz');
            $this->fail('->get() throws a NotFoundServiceException if the service definition does not exist');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('The "baz" requested service is not defined in container. Did you mean: "bar"?', $e->getMessage());
        }

        try {
            $container->get('foo');
            $container->set('foo', new ValueDefinition(2021));
            $this->fail('->set() expects to fail as service is already initialized');
        } catch (FrozenServiceException $e) {
            $this->assertEquals('The "foo" service is already initialized, and cannot be replaced.', $e->getMessage());
        }

        try {
            $container->get(Fixtures\ServiceAutowire::class);
            $this->fail('->get() throws a NotFoundServiceException if the service could not be resolved');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('The "Rade\DI\Tests\Fixtures\ServiceAutowire" requested service is not defined in container.', $e->getMessage());
        }
    }

    public function testChildDefinition(): void
    {
        $container = $this->getContainer();

        $container->set('foo', new Definition(\stdClass::class))->bind('hello', 'world')->abstract();
        $container->set('foobar', new Fixtures\Service());
        $container->set('bar', new Reference('foo'));

        $this->assertNotSame($foo = $container->definition('foo'), $bar = $container->definition('bar'));
        $this->assertEquals($foo->getBindings(), $bar->getBindings());

        try {
            $container->set('foobaz', new Reference('parent'));
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('The "parent" requested service is not defined in container.', $e->getMessage());
        }

        $container->set('baz', new Reference('foobar'));
        $this->assertNotSame($container->definition('bar'), $container->definition('foobar'));
    }

    public function testAbstractDefinition(): void
    {
        $container = $this->getContainer();

        $container->set('foo', new Definition(static fn () => 'strlen'))->abstract();
        $container->set('bar', new ValueDefinition(2022))->abstract();
        $this->assertTrue($container->definition('foo')->isAbstract());
        $this->assertTrue($container->definition('bar')->isAbstract());

        try {
            $container->get('foo');
            $this->fail('->abstract() expects a error on abstract definition');
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Resolving an abstract definition foo is not allowed.', $e->getMessage());
        }

        $this->expectExceptionObject(new ContainerResolutionException('Resolving an abstract definition bar is not allowed.'));
        $container->get('bar');
    }

    public function testDefinitionBindings(): void
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition(Fixtures\FooClass::class))
            ->arg('arguments', [[]])
            ->bind('$foo', 'Hello World')
            ->bind('$moo')
            ->bind('removeThis')
            ->bind('setBar')
            ->binds(['initialize' => null, 'setBar' => ['hello', 'world']])
            ->bind('setBar', new Statement(Fixtures\Service::class))
            ->call(new Statement([NullLogger::class, 'notice'], ['Logging this definition']))
            ->call(new Statement(Fixtures\FooClass::class . '::getInstance'), true)
        ;
        $definition = $container->definition('foo');

        $this->assertTrue($definition->hasArguments());
        $this->assertTrue($definition->hasBinding());
        $this->assertCount(5, $definition->getBindings());
        $this->assertCount(2, $definition->getExtras());
        $this->assertEquals(['foo' => 'Hello World', 'moo' => null], $definition->getParameters());

        $definition->unbind('removeThis');
        $definition->unbind('1.setBar');
        $definition->unbind('$moo');
        $this->assertCount(3, $definition->getBindings());

        if ($container instanceof ContainerBuilder) {
            $definition->bind('noneExist');
            $node = $container->get('foo', ContainerBuilder::BUILD_SERVICE_DEFINITION);

            $this->assertInstanceOf(ClassMethod::class, $node);
            $this->assertEquals('service', (string) $node->getStmts()[0]->expr->var->name);
            $this->assertEquals(Fixtures\FooClass::class, (string) $node->getStmts()[0]->expr->expr->class);
            $this->assertInstanceOf(PropertyFetch::class, $node->getStmts()[1]->expr->var);
            $this->assertInstanceOf(MethodCall::class, $node->getStmts()[2]->expr);
            $this->assertEquals('initialize', (string) $node->getStmts()[2]->expr->name);
            $this->assertInstanceOf(MethodCall::class, $node->getStmts()[3]->expr);
            $this->assertEquals('setBar', (string) $node->getStmts()[3]->expr->name);
            $this->assertInstanceOf(MethodCall::class, $node->getStmts()[4]->expr);
            $this->assertEquals('setBar', (string) $node->getStmts()[4]->expr->name);
            $this->assertInstanceOf(MethodCall::class, $node->getStmts()[5]->expr);
            $this->assertEquals('noneExist', (string) $node->getStmts()[5]->expr->name);
            $this->assertInstanceOf(MethodCall::class, $node->getStmts()[6]->expr);
            $this->assertEquals('notice', (string) $node->getStmts()[6]->expr->name);
            $this->assertInstanceOf(StaticCall::class, $node->getStmts()[7]->expr);
            $this->assertEquals('getInstance', (string) $node->getStmts()[7]->expr->name);

            try {
                $definition->bind('$moo', new PhpLiteral('return 1;'));
                $container->get('foo', ContainerBuilder::BUILD_SERVICE_DEFINITION);
                $this->fail('->bind() expects to lazily throw an exception on using stmt node as value');
            } catch (ContainerResolutionException $e) {
                $this->assertEquals('Constructing property "moo" for service "foo" failed, expression not supported.', $e->getMessage());
            }
        } else {
            $foo = $container->get('foo');
            $this->assertEquals('Hello World', $foo->foo);
            $this->assertInstanceOf(Fixtures\Service::class, $foo->bar);
        }
    }

    public function testDefinitionDeprecation(): void
    {
        $container = $this->getContainer();
        $def = $container->set('deprecate_service', new Definition(Fixtures\Service::class))->deprecate();

        $deprecation = $def->getDeprecation();
        $message = 'The "deprecate_service" service is deprecated. avoid using it, as it will be removed in the future.';
        $this->assertSame($message, $deprecation['message']);
        $this->assertTrue($def->isDeprecated());

        if ($container instanceof Container) {
            $container->get('deprecate_service');
            $this->assertEquals([
                'type' => \E_USER_DEPRECATED,
                'message' => 'The "deprecate_service" service is deprecated. avoid using it, as it will be removed in the future.',
            ], \array_intersect_key(\error_get_last(), ['type' => true, 'message' => true]));
        } else {
            $node = $container->get('deprecate_service', ContainerBuilder::BUILD_SERVICE_DEFINITION);
            $this->assertInstanceOf(ClassMethod::class, $node);
            $this->assertEquals('trigger_deprecation', (string) $node->getStmts()[0]->expr->name);
        }

        $container->reset();

        $this->expectExceptionObject(new \InvalidArgumentException('The deprecation template must contain the "%service_id%" placeholder.'));
        $container->set('foo', new Definition(Fixtures\Service::class))->deprecate('', null, 'foo is deprecated');
    }

    public function testAutowire(): void
    {
        $container = $this->getContainer();

        $container->autowire('foo', new Definition(Fixtures\ServiceAutowire::class));
        $this->assertEquals(['foo'], $container->typed(Fixtures\ServiceAutowire::class, true));

        $def = $container->definition('foo')->replace(Fixtures\FooClass::class, true);
        $this->assertEmpty($container->typed(Fixtures\ServiceAutowire::class, true));

        $this->assertTrue($container->has('foo'));
        $this->assertTrue($container->typed(Fixtures\FooClass::class));
        $this->assertEquals(['foo'], $container->typed(Fixtures\FooClass::class, true));
        $this->assertEquals([Fixtures\FooClass::class], $def->getTypes());
        $this->assertTrue($def->isTyped());

        $autowired = $container->autowired(Fixtures\FooClass::class, true);
        $this->assertCount(1, $container->autowired(Fixtures\FooClass::class));
        $this->assertTrue($autowired instanceof Fixtures\FooClass || $autowired instanceof Coalesce);

        $container->autowire('bar', (new Definition(Fixtures\Service::class))->typed([Fixtures\FooClass::class]));
        $this->assertCount(2, $container->autowired(Fixtures\FooClass::class));

        try {
            $container->autowired(Fixtures\FooClass::class, true);
            $this->fail('->autowired() is expected to throw an exception as multiple types exists');
        } catch (ContainerResolutionException $e) {
            $this->assertEquals(\sprintf('Multiple services of type %s found: bar, foo.', Fixtures\FooClass::class), $e->getMessage());
        }

        $container->removeDefinition('foo');
        $this->assertEquals([1 => 'bar'], $container->typed(Fixtures\FooClass::class, true));

        $container->excludeType(Fixtures\BarInterface::class);
        $container->set('foo', new Definition(Fixtures\Service::class))->autowire([Fixtures\BarInterface::class, 'string']);
        $this->assertFalse($container->typed(Fixtures\BarInterface::class));

        $container->reset();

        try {
            $container->types([Fixtures\FooClass::class]);
            $this->fail('->types() is not allowed to contained indexed array');
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Service identifier is not defined, integer found.', $e->getMessage());
        }

        $this->expectExceptionObject(new NotFoundServiceException(\sprintf('Service of type "%s" not found. Check class name because it cannot be found.', Fixtures\FooClass::class)));
        $container->autowired(Fixtures\FooClass::class);
    }

    public function testHas()
    {
        $container = $this->getContainer();

        $this->assertFalse($container->has('foo'));
        $container->set('foo', new Definition('Bar\FooClass'));
        $this->assertTrue($container->has('foo'));

        return $container;
    }

    public function testGetThrowsExceptionIfServiceDoesNotExist(): void
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('The "foo" requested service is not defined in container.');

        $container = $this->getContainer();
        $container->get('foo');
    }

    public function testGetReturnsNullIfServiceDoesNotExist(): void
    {
        $container = $this->getContainer();
        $this->assertNull($container->get('foo', ContainerInterface::NULL_ON_INVALID_SERVICE));
    }

    public function testGetThrowsCircularReferenceExceptionIfServiceHasReferenceToItself(): void
    {
        $this->expectExceptionMessage('Circular reference detected for service "baz", path: "baz -> baz".');
        $this->expectException(CircularReferenceException::class);

        $container = $this->getContainer();
        $container->set('baz', new Statement(Fixtures\Bar::class, [new Reference('baz')]));
        $container->get('baz');
    }

    public function testIndirectCircularReference(): void
    {
        $container = $this->getContainer();

        $container->set('a', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('b')]);
        $container->set('b', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('c')]);
        $container->set('c', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $container->get('a');
    }

    public function testIndirectDeepCircularReference(): void
    {
        $container = $this->getContainer();

        $container->set('a', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('b')]);
        $container->set('b', new Definition([new Reference('c'), 'getInstance']));
        $container->set('c', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $container->get('a');
    }

    public function testDeepCircularReference(): void
    {
        $container = $this->getContainer();

        $container->set('a', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('b')]);
        $container->set('b', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('c')]);
        $container->set('c', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('b')]);

        $this->expectExceptionMessage('Circular reference detected for service "b", path: "a -> b -> c -> b".');
        $this->expectException(CircularReferenceException::class);

        $container->get('a');
    }

    public function testCircularReferenceWithCallableAlike(): void
    {
        $container = $this->getContainer();

        $container->set('a', new Definition([new Reference('b'), 'getInstance']));
        $container->set('b', new Definition([new Reference('a'), 'getInstance']));

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $container->get('a');
    }

    public function testCircularReferenceChecksMethodsCalls(): void
    {
        $container = $this->getContainer();

        $container->autowire('a', new Definition(Fixtures\Constructor::class))->args([new Reference('b')]);
        $container->set('b', new Definition(Fixtures\ServiceAutowire::class))->bind('missingService', new Reference('a'));

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $container->get('a');
    }

    public function testCircularReferenceChecksLazyServices(): void
    {
        $container = $this->getContainer();

        $container->set('a', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('b')])->lazy();
        $container->set('b', new Definition(Fixtures\ServiceAutowire::class))->args([new Reference('a')]);
        $this->assertTrue($container->definition('a')->isLazy());

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        // Unless no arguments are provided, circular referencing is ignored
        $container->get('a');
    }

    public function testGetReturnsSameInstanceWhenServiceIsShared(): void
    {
        $container = $this->getContainer();
        $container->set('bar', new Definition('stdClass'));
        $container->set('baz', $baz = new \stdClass());

        $container->get('baz');
        $this->assertSame($container->get('bar'), $container->get('bar'));

        if ($container instanceof Container) {
            $this->assertEquals($baz, $container->get('baz'));
        }

        $this->assertTrue($container->shared('baz'));
        $this->assertTrue($container->shared('bar'));

        $container->set('foo', $baz);
        $this->assertSame($container->get('foo'), $container->get('foo'));
        $this->assertTrue($container->shared('foo'));
    }

    public function testNonSharedServicesReturnsDifferentInstances(): void
    {
        $container = $this->getContainer();
        $container->set('bar', new Definition('stdClass'))->shared(false);
        $container->set('foo', new ValueDefinition('stdClass'))->shared(false);
        $container->set('baz', new \stdClass());

        $a = $container->get('bar');
        $b = $container->get('foo');
        $c = $container->get('baz');

        $this->assertTrue($container->shared('baz'));
        $this->assertSame($b, $container->get('foo'));
        $this->assertTrue($container instanceof ContainerBuilder || !$container->shared('bar'));

        if (!$container instanceof ContainerBuilder) {
            $this->assertNotSame($a, $container->get('bar'));
            $this->assertSame($c, $container->get('baz'));
            $this->assertFalse($container->shared('foo'));
        }
    }

    public function testGetCreatesServiceBasedOnDefinition(): void
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition('stdClass'));

        $this->assertIsObject($container->get('foo'));
    }

    public function testGetReturnsRegisteredDefinition(): void
    {
        $container = $this->getContainer();

        $container->set('bar', new Definition('stdClass'));
        $this->assertInstanceOf($container instanceof Container ? \stdClass::class : Coalesce::class, $container->get('bar'));
    }

    public function testCaseSensitivity(): void
    {
        $container = $this->getContainer();
        $container->set('foo', $foo1 = new \stdClass());
        $container->set('Foo', $foo2 = new \stdClass());

        $this->assertSame(['foo', 'Foo'], \array_keys($container->definitions()));
        $this->assertSame($foo1, $container->definition('foo'));
        $this->assertSame($foo2, $container->definition('Foo'));
    }

    public function testGetServiceIds(): void
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition('stdClass'));
        $container->set('bar', new \stdClass());
        $this->assertEquals(['foo', 'bar'], \array_keys($container->definitions()));
    }

    public function testRemoveService()
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition('stdClass'));
        $container->set('bar', new \stdClass());
        $this->assertEquals(['foo', 'bar'], \array_keys($container->definitions()));

        $container->removeDefinition('foo');
        $this->assertEquals(['bar'], \array_keys($container->definitions()));

        return $container;
    }

    public function testAlias(): void
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition(\stdClass::class));

        $this->assertFalse($container->has('bar'));
        $this->assertFalse($container->aliased('foo'));

        $container->alias('bar', 'foo');
        $container->alias('bat', 'bar');

        $this->assertTrue($container->has('bar'));
        $this->assertTrue($container->has('bat'));
        $this->assertTrue($container->aliased('foo'));

        $container->removeAlias('bat');
        $this->assertFalse($container->has('bat'));

        $container->set('foobar', new Definition(\stdClass::class));
        $container->alias('bat', 'foobar');
        $this->assertTrue($container->aliased('foobar'));

        $container->set('bat', new Definition('FooClass'));
        $this->assertFalse($container->aliased('foobar'));

        $container->alias('baz', 'bat');
        $this->assertTrue($container->aliased('bat'));

        $container->removeDefinition('foo');
        $this->assertEquals($container->has('foo'), $container->aliased('foo'));
    }

    public function testAliasWithSameNameAsId(): void
    {
        $this->expectExceptionObject(new \LogicException('[foo] is aliased to itself.'));

        $container = $this->getContainer();
        $container->alias('foo', 'foo');
    }

    public function testAliasWithServiceIdNotFound(): void
    {
        $this->expectExceptionMessage('The "nothing" requested service is not defined in container.');
        $this->expectException(NotFoundServiceException::class);

        $container = $this->getContainer();
        $container->alias('name', 'nothing');
    }

    public function testAliasOnTypedService(): void
    {
        $container = $this->getContainer();
        $container->autowire('foo', new Definition(Fixtures\Service::class));
        $container->set('bar', new Definition(Fixtures\Constructor::class))->typed([Fixtures\Constructor::class, Fixtures\Service::class]);

        $container->alias('BarClass', Fixtures\Constructor::class);
        $this->assertTrue($container->has('BarClass'));
        $this->assertSame($container->get('BarClass'), $container->get('bar'));

        $this->expectExceptionMessage('Aliasing an alias of "FooClass" on a multiple defined type "Rade\DI\Tests\Fixtures\Service" is not allowed.');
        $this->expectException(ContainerResolutionException::class);

        $container->alias('FooClass', Fixtures\Service::class);
    }

    public function testGetSetParameter(): void
    {
        $container = $this->getContainer();

        $container->parameters['foo'] = 'bar';
        $container->parameters['bar'] = 'foo';
        $this->assertEquals('foo', $container->parameters['bar']);
        $this->assertEquals('bar', $container->parameter('%foo%'));

        $container->parameters['foo'] = 'baz';
        $this->assertEquals('baz', $container->parameters['foo']);
        $this->assertArrayNotHasKey('baba', $container->parameters);

        $container->parameters['foobar'] = $expected = ['a', 'b', 'c'];
        $this->assertEquals($expected, $container->parameter('%foobar%'));

        $container->parameters['greet'] = 'Hello';
        $container->parameters['name'] = 'Divine';
        $this->assertEquals('Hello Divine %', $container->parameter('Hello Divine %'));
        $this->assertEquals('Hello how are you Divine', $container->parameter('%greet% how are you %name%'));

        try {
            $container->parameter('%greet% in %foobar%');
            $this->fail('->parameter() throws an InvalidArgumentException if a key is non-scalar.');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertEquals('Unable to concatenate non-scalar parameter "foobar" into %greet% in %foobar%.', $e->getMessage());
        }

        try {
            $container->parameter('%baba%');
            $this->fail('->parameter() throws an InvalidArgumentException if the key does not exist');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('You have requested a non-existent parameter "baba".', $e->getMessage());
        }

        $resolve = $container->call(Fixtures\FooClass::class, [[param('foo')]]);

        if ($resolve instanceof New_) {
            $this->assertEquals('foo', $resolve->args[0]->value->items[0]->value->dim->value);
        } else {
            $this->assertEquals(['baz'], $resolve->arguments);
        }
    }

    public function testTagging(): void
    {
        $container = $this->getContainer();
        $definition = new Definition(Fixtures\Service::class);

        $definition->tags(['foo']);
        $this->assertEquals(['foo' => true], $definition->getTags());
        $this->assertTrue($definition->tagged('foo'));
        $this->assertTrue($definition->hasTags());

        $container->tag(Fixtures\Constructor::class, ['foo']);
        $container->set('baz', $definition)->tag('bar');

        $this->assertCount(1, $container->tagged('bar'));
        $this->assertCount(2, $container->tagged('foo'));
        $this->assertEquals(['foo' => true, 'bar' => true], $definition->getTags());

        $fooResults = $barResults = [];
        $this->assertEquals(['foo' => true, 'bar' => true], $container->definition('baz')->getTags());

        foreach ($container->tagged('foo') as $foo => $fooV) {
            $fooResults[] = $container->get($foo);
        }

        foreach ($container->tagged('bar') as $bar => $barV) {
            $barResults[] = $container->get($bar);
        }

        $this->assertCount(2, $fooResults);
        $this->assertCount(1, $barResults);

        $container->removeDefinition('baz');
        $this->assertCount(0, $container->tagged('bar'));
        $this->assertCount(1, $container->tagged('foo'));

        $container->removeTag('foo');
        $this->assertCount(0, $container->tagged('foo'));

        $container->tags(['foo' => ['baz'], 'bar' => ['baz' => 'hello']]);
        $this->assertEquals(['baz' => true], $container->tagged('foo'));
        $this->assertEquals(['baz' => 'hello'], $container->tagged('bar'));
    }

    public function testExtendingService(): void
    {
        $container = $this->getContainer();

        $container->set('foo', fn ($v = 'foo') => $v);
        $container->set('bar', new Definition(Fixtures\Service::class));
        $container->set('baz', new ValueDefinition(20));

        $container->extend('foo', function (string $foo) {
            return "$foo.bar";
        });
        $container->extend('foo', function (string $foo) {
            return "$foo.baz";
        });
        $this->assertEquals('foo.bar.baz', $container->definition('foo'));

        $container->extend('bar', function (Definition $bar) {
            $bar->bind('$value', 'HelloWorld');

            return $bar;
        });
        $this->assertEquals(['value' => 'HelloWorld'], $container->definition('bar')->getParameters());

        $container->extend('baz', function (ValueDefinition $baz) {
            $baz->replace($baz->getEntity() . 22);

            return $baz;
        });
        $this->assertEquals('2022', $container->definition('baz')->getEntity());

        $container->removeDefinition('foo');
        $container->removeDefinition('bar');
        $container->set('foo', fn () => $container->get('bar'));
        $container->set('bar', fn () => $container->get('foo'));

        try {
            $container->extend('foo', fn ($foo, $rade) => $rade);
            $this->fail('->extend() throws a CircularReferenceException if self referenced');
        } catch (CircularReferenceException $e) {
            $this->assertEquals(['bar', 'foo', 'bar'], $e->getPath());
            $this->assertEquals('bar', $e->getServiceId());
            $this->assertEquals('Circular reference detected for service "bar", path: "bar -> foo -> bar".', $e->getMessage());
        }

        $container->removeDefinition('foo');
        $container->removeDefinition('bar');
        $container->set('foo', fn (Fixtures\BarInterface $service) => $service);
        $container->set('bar', fn (Fixtures\FooClass $service) => $service);
        $container->types(['foo' => Fixtures\FooClass::class, 'bar' => Fixtures\BarInterface::class]);

        try {
            $container->extend('foo', fn ($foo, $rade) => $rade);
            $this->fail('->extend() throws a CircularReferenceException if self parameter referenced');
        } catch (CircularReferenceException $e) {
            $this->assertEquals(['bar', 'foo', 'bar'], $e->getPath());
            $this->assertEquals('bar', $e->getServiceId());
            $this->assertEquals('Circular reference detected for service "bar", path: "bar -> foo -> bar".', $e->getMessage());
        }

        $this->expectExceptionObject(new NotFoundServiceException('The "one" requested service is not defined in container'));
        $container->extend('one', fn () => null);
    }

    public function testRunScope(): void
    {
        $container = $this->getContainer();
        $container->set('foo', new Definition(Fixtures\FooClass::class));
        $b = $container->runScope(['actor' => new Fixtures\Service()], function () use ($container) {
            return $container->get('actor');
        });
        $this->assertTrue($b instanceof Coalesce || $b instanceof Fixtures\Service);
        $this->assertFalse($container->has('actor'));

        $this->expectExceptionObject(new ContainerResolutionException('Service with id "foo" exist in container and cannot be redeclared.'));
        $container->runScope(['foo' => new Fixtures\Service()], fn () => null);
    }

    public function testDecorativeServices(): void
    {
        $container = $this->getContainer();

        $container->set('foo', new Definition(Fixtures\SomeService::class));
        $container->decorate('foo', new Definition(Fixtures\FooClass::class, [new Reference('foo.inner')]));

        $this->assertTrue($container->definition('foo.inner')->tagged('container.decorated_services'));
        $this->assertEquals(['foo.inner' => true], $container->tagged('container.decorated_services'));

        $container->get('foo'); // No exception should be thrown
        $container->reset();
        $this->assertEmpty($container->definitions());

        $this->expectExceptionObject(new NotFoundServiceException('The "foo" requested service is not defined in container'));
        $container->decorate('foo');
    }

    public function testFindBy(): void
    {
        $container = $this->getContainer();

        $container->set('foo', new Definition(Fixtures\FooClass::class))->tag('a')->autowire([Fixtures\FooClass::class]);
        $container->set('bar', new Definition(Fixtures\Service::class))->tag('a')->autowire([Fixtures\FooClass::class]);
        $this->assertEquals(['foo', 'bar'], $container->findBy('a'));
        $this->assertEquals(['foo', 'bar'], $container->findBy(Fixtures\FooClass::class));

        foreach ($container->findBy('a', fn (string $v) => new Reference($v)) as $reference) {
            $this->assertInstanceOf(Reference::class, $reference);
        }
    }

    public function testFluentRegister(): void
    {
        $container = $this->getContainer();
        $extension = new ExtensionBuilder($container, [
            'php' => ['date.timezone' => \date_default_timezone_get()],
            Fixtures\ProjectServiceProvider::class => ['foo' => 'Hello World'],
            'rade_provider' => __DIR__ . '/Fixtures/config_builder/config.php',
        ]);
        $extension->setConfigBuilderGenerator(__DIR__ . '/Fixtures/config_builder');
        $extension->load([
            PhpExtension::class,
            Fixtures\RadeServiceProvider::class => 100,
            Fixtures\ProjectServiceProvider::class,
        ]);

        $this->assertTrue(isset($container->parameters['rade_di']['hello']));
        $this->assertArrayHasKey('other', $container->parameters);
        $this->assertArrayHasKey('project.configs', $container->parameters);
        $this->assertEquals(['hello World'], $container->parameters['project.configs']['values']);
        $this->assertEquals($container->get('other'), $container->get(AbstractContainer::SERVICE_CONTAINER));
        $this->assertEquals(['date.timezone' => 'Africa/Ghana'], $extension->getConfig(PhpExtension::class));
        $this->assertCount(5, $container->definitions());
        $this->assertCount(4, $extension->getExtensions());
        $this->assertTrue($container->has('param'));
        $this->assertTrue($container->has('service'));
        $this->assertTrue($container->has('factory'));
        $this->assertTrue($container->has('project.service.bar'));
        $this->assertTrue($container->has('project.service.foo'));

        try {
            $extension->load([PhpExtension::class, PhpExtension::class]);
            $this->fail('->load() expects to throw a exception on duplicated alias');
        } catch (\RuntimeException $e) {
            $this->assertEquals('The aliased id "php" for Rade\DI\Extensions\PhpExtension extension class must be unqiue.', $e->getMessage());
        }

        try {
            $extension->getConfig('none');
            $this->fail('->getConfig() expects to throw a exception on invalid extension name');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('The extension "none" provided in not valid, must be an extension\'s class name or alias.', $e->getMessage());
        }

        try {
            $extension->modifyConfig('none', []);
            $this->fail('->modifyConfig() expects to throw a exception on invalid extension name');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('The extension "none" provided in not valid, must be an extension\'s class name.', $e->getMessage());
        }

        $extension->get(PhpExtension::class)->setConfig('max_execution_time', 300);
        $this->assertNotEmpty($extension->getConfigs());

        $container->reset();
        $extension = new ExtensionBuilder($container, ['parameters' => ['test' => 'stored']]);
        $extension->load([]);
        $this->assertEmpty($container->definitions());
        $this->assertNotEmpty($container->parameters);
    }

    public function testDefinitionBuilder(): void
    {
        $definitions = new DefinitionBuilder($this->getContainer());
        $container = $definitions->getContainer();

        $definitions
            ->defaults()->tag('def_builder')
            ->directory(__DIR__ . '/Fixtures')

            ->namespaced('Rade\DI\Tests\Fixtures\Prototype\\', null, 'Prototype/{OtherDir,BadClasses,SinglyImplementedInterface}')
                ->bind('Cool', 'This is nice')
                ->autowire([Fixtures\Bar::class])

            ->namespaced('Psr\\Log\\', __DIR__ . '/../vendor/psr/log', 'src/InvalidArgumentException')

            ->instanceOf(Fixtures\Service::class)->typed([Fixtures\Service::class])

            ->set('hello_foobar', wrap([reference(Fixtures\MusicMe::class), 'variadic'], [], true))->autowire(['array'])

            ->autowire('another', service(Fixtures\MusicMe::class, ['%greet%']))->arg(3, $f = $container->call(DefaultValueResolver::class))
                ->bind('variadic', [])
                ->bind('$named_resolver', reference('type_value'))
                ->call([reference('named_value'), 'cool'])
            ;

        if ($container instanceof ContainerBuilder) {
            $definitions->bind('ddd', phpCode('static fn () => \'%?\';', [$f]));
        }

        $definitions->set('has_container', service(Fixtures\Constructor::class)->autowire([Fixtures\Constructor::class]));

        $this->assertCount(2, $container->typed(Fixtures\Bar::class, true));
        $this->assertEquals(['has_container'], $container->typed(Fixtures\Service::class, true));
        $this->assertTrue($container->definitions() >= 8);
        $this->assertTrue($container->tagged('def_builder') >= 7);
        $this->assertTrue($container->has(NullLogger::class));
        $this->assertTrue($container->has(\Psr\Log\LogLevel::class));

        try {
            $definitions->namespaced('Rade\DI\Tests\Fixtures\Prototype\Sub', 'Prototype/*');
            $this->fail('->namespaced() expects an exception as namespace is not valid');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('Namespace prefix must end with a "\\": "Rade\DI\Tests\Fixtures\Prototype\Sub".', $e->getMessage());
        }

        try {
            $definitions->namespaced('0Rade_DI_Tests_Fixtures_Prototype_Sub\\', 'Prototype/*');
            $this->fail('->namespaced() expects an exception as namespace is not valid');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('Namespace is not a valid PSR-4 prefix: "0Rade_DI_Tests_Fixtures_Prototype_Sub\\".', $e->getMessage());
        }

        try {
            $definitions->namespaced('Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*');
            $this->fail('->namespaced() expects an exception as resource path maybe invalid');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('You have requested a non-existent parameter "sub_dir".', $e->getMessage());
        }

        try {
            $definitions->namespaced('Rade\DI\Tests\Fixtures\Prototype\\OtherDir\\', 'Prototype/Sub/*');
            $this->fail('->namespaced() expects an exception as classes cannot be found');
        } catch (\InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/Expected to find class "Rade\\\DI\\\Tests\\\Fixtures\\\Prototype\\\OtherDir\\\Sub\\\Bar" in file ".+" while importing services from resource ".+Prototype\/Sub\/\*", but it was not found\! Check the namespace prefix used with the resource/', $e->getMessage());
        }

        try {
            $definitions->fail();
            $this->fail('->fail() expects an exception as method doesn\'t exists');
        } catch (\BadMethodCallException $e) {
            $this->assertEquals('Call to undefined method fail() method must either belong to an instance of Rade\DI\Definitions\DefinitionInterface or the Rade\DI\DefinitionBuilder class', $e->getMessage());
        }

        $container->reset();
        $definitions->reset()
            ->parameter('sub_dir', 'Sub')
            ->directory(__DIR__ . '/Fixtures')
            ->namespaced('Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*')
            ->load() // Loads the definitions in namespace since no new new service is created
        ;

        $this->assertTrue($container->has(Fixtures\Prototype\Sub\Bar::class));
        $this->assertFalse($container->has(Fixtures\Prototype\Sub\BarInterface::class)); // Found if autowired

        $container->reset();
        $definitions->reset()
            ->parameter('other_dir', 'OtherDir')
            ->directory(__DIR__ . '/Fixtures')

            // load everything, except OtherDir/AnotherSub & Foo.php
            ->namespaced('Rade\DI\Tests\Fixtures\Prototype\\', 'Prototype/*', 'Prototype/{%other_dir%/AnotherSub,Foo.php}')
            ->load() // Loads the definitions in namespace since no new new service is created
        ;

        $this->assertCount(8, $container->definitions());
        $this->assertTrue($container->has(Fixtures\Prototype\Sub\Bar::class));
        $this->assertTrue($container->has(Fixtures\Prototype\OtherDir\Baz::class));
        $this->assertFalse($container->has(Fixtures\Prototype\Foo::class));
        $this->assertFalse($container->has(Fixtures\Prototype\OtherDir\AnotherSub\DeeperBaz::class));

        $container->reset();
        $definitions->reset();

        try {
            $definitions->bind('cool', []);
            $this->fail('->bind() expects an exception as service is not registerd');
        } catch (\LogicException $e) {
            $this->assertEquals('Did you forget to register a service via "set", "autowire", or "namespaced" methods\' before calling the bind() method.', $e->getMessage());
        }

        try {
            $definitions->autowire([]);
            $this->fail('->autowire() expects an exception as service is not registerd');
        } catch (\LogicException $e) {
            $this->assertEquals('Did you forget to register a service via "set", "autowire", or "namespaced" methods\' before calling the autowire() method.', $e->getMessage());
        }

        $definitions
            ->set('foo', new Definition(Fixtures\FooClass::class))
            ->decorate('foo', new Definition(Fixtures\Service::class))
            ->alias('bar', 'foo.inner')
        ;

        $this->assertEquals(Fixtures\Service::class, $container->definition('foo')->getEntity());
        $this->assertEquals($container->get('bar'), $container->get('foo.inner'));
    }

    public function testInjectAndTaggedAttribute(): void
    {
        if (\PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Skip test because PHP version is lower than 8.0');
        }

        $container = $this->getContainer();
        $container->autowire('bar', service(Fixtures\Constructor::class, [reference('container')]));
        $container->autowire('foo', service(Fixtures\FooClass::class, [['inject' => true]]));
        $container->tags(['a' => ['bar', 'foo']]);

        $inject = $container->getResolver()->resolveClass(Fixtures\InjectableClass::class);
        $bar = $container->get('bar');
        $foo = $container->get('foo');

        $anotherInject = $container->getResolver()->resolveCallable(Fixtures\ClassWithInject::class . '::InjectParameter');
        $tagged = $container->getResolver()->resolveCallable(Fixtures\ClassWithInject::class . '::injectTag');

        if ($inject instanceof Fixtures\InjectableClass) {
            $this->assertSame($bar, $inject->getService());
            $this->assertSame($foo, $inject->getFooClass());
            $this->assertSame($bar, $inject->getBarService());
            $this->assertSame([$container, $foo], $anotherInject);
            $this->assertEquals([$bar, $foo], $tagged);
        } else {
            $this->assertSame(['bar' => $bar, 'service' => $bar], $inject->getProperties());
            $this->assertSame(['injectFooClass' => [$foo]], $inject->getMethods());
            $this->assertSame($foo, $anotherInject->args[1]->value);
            $this->assertSame($bar, $tagged->args[0]->value->items[0]->value);
            $this->assertSame($foo, $tagged->args[0]->value->items[1]->value);
        }
    }

    public function testShouldPassContainerTypeHintAsParameter(): void
    {
        $container = $this->getContainer();
        $container->autowire('service', service(Fixtures\Service::class));
        $container->autowire('construct', wrap(Fixtures\Constructor::class));

        if (!$container instanceof ContainerBuilder) {
            $this->assertSame($container, $container->get('construct')->value);
        } else {
            $this->assertSame(
                $container->get(AbstractContainer::SERVICE_CONTAINER),
                $container->get('construct', ContainerBuilder::BUILD_SERVICE_DEFINITION)->stmts[0]->expr->expr->args[0]->value
            );
        }
    }
}
