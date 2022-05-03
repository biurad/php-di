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
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use Psr\Container\ContainerInterface;
use Rade\DI\AbstractContainer;
use Rade\DI\ContainerBuilder;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Definitions\Statement;
use Rade\DI\Definitions\ValueDefinition;

use function Composer\Autoload\includeFile;

/**
 * @group required
 */
class ContainerBuilderTest extends AbstractContainerTest
{
    private const COMPILED = __DIR__ . '/Fixtures/compiled';

    public function getContainer(string $containerParentClass = Container::class): AbstractContainer
    {
        return new ContainerBuilder($containerParentClass);
    }

    public function testContainer(): void
    {
        $builder = parent::testContainer();

        $this->assertTrue($builder->shared('container'));
        $this->assertInstanceOf(Variable::class, $builder->get(Container::class));
        $this->assertInstanceOf(Variable::class, $builder->get(AbstractContainer::class));
        $this->assertInstanceOf(Variable::class, $builder->get(ContainerInterface::class));
        $this->assertInstanceOf(Variable::class, $builder->get('container'));
    }

    public function testValueDefinition(): void
    {
        $builder = $this->getContainer();
        $definitions = [
            'foo' => new ValueDefinition($foo = 'value'),
            'bar' => new ValueDefinition($bar = 345),
            'baz' => new ValueDefinition($baz = ['a' => 'b', 'c' => 'd']),
            'recur' => new ValueDefinition(['a' => ['b' => ['hi' => new ValueDefinition(434.54)], 'c' => 'd']]),
            'callback' => new ValueDefinition(fn (string $string) => \strlen($string)),
            'noxe' => new ValueDefinition($builder->call(Fixtures\FooClass::class)),
        ];
        $builder->multiple($definitions);

        $this->assertSame($definitions, $builder->definitions());
        $this->assertInstanceOf(ValueDefinition::class, $service1 = $builder->definition('foo'));
        $this->assertInstanceOf(ValueDefinition::class, $service2 = $builder->definition('bar'));
        $this->assertInstanceOf(ValueDefinition::class, $service3 = $builder->definition('baz'));
        $this->assertInstanceOf(ValueDefinition::class, $service4 = $builder->definition('recur'));
        $this->assertInstanceOf(ValueDefinition::class, $builder->definition('callback'));
        $this->assertInstanceOf(New_::class, $builder->definition('noxe')->getEntity());

        $this->assertEquals($foo, $service1->getEntity());
        $this->assertEquals($bar, $service2->getEntity());
        $this->assertEquals($baz, $service3->getEntity());

        $recursive = $builder->getResolver()->resolveArguments($service4->getEntity());
        $this->assertEquals(['hi' => 434.54], $recursive['a']['b']);
        $this->assertInstanceOf(\PhpParser\Node\Scalar\String_::class, $recursive['a']['c']);

        try {
            $builder->get('callback');
            $this->fail('->get() expects to throw an exception on callable value');
        } catch (\LogicException $e) {
            $this->assertEquals('Invalid value', $e->getMessage());
        }

        $builder->removeDefinition('callback');
        $this->assertStringEqualsFile(self::COMPILED . '/service1_' . ((int) \PHP_MAJOR_VERSION >= 8) . '.phpt', $builder->compile());
    }

    public function testPrivateDefinition(): void
    {
        $builder = $this->getContainer();

        $builder->set('foo', new Definition(Fixtures\Service::class))->public(false);
        $this->assertFalse($builder->definition('foo')->isPublic());

        $node = $builder->get('foo', ContainerBuilder::BUILD_SERVICE_DEFINITION);
        $this->assertInstanceOf(ClassMethod::class, $node);
        $this->assertEquals('privates', (string) $node->getStmts()[0]->expr->var->var->name);
    }

    public function testFactoryDefinition(): void
    {
        $builder = $this->getContainer();

        $builder->set('foo', new Definition(Fixtures\Service::class))->shared(false);
        $this->assertFalse($builder->definition('foo')->isShared());

        $node = $builder->get('foo', ContainerBuilder::BUILD_SERVICE_DEFINITION);
        $this->assertInstanceOf(ClassMethod::class, $node);
        $this->assertEquals(Fixtures\Service::class, (string) $node->getStmts()[0]->expr->class);
    }

    public function testLazyDefinition(): void
    {
        $builder = $this->getContainer();

        $builder->set('foo', new Definition(Fixtures\Service::class))->lazy(true);
        $this->assertTrue($builder->definition('foo')->isLazy());

        $node = $builder->get('foo', ContainerBuilder::BUILD_SERVICE_DEFINITION);
        $this->assertInstanceOf(ClassMethod::class, $node);
        $this->assertEquals('resolver', (string) $node->getStmts()[0]->expr->expr->name);
    }

    public function testCompileMethod(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('foo', new Definition(Fixtures\Service::class));

        $this->assertNotEmpty($builder->compile());
        $this->assertIsString($builder->compile());
        $this->assertIsArray($builder->compile(['printToString' => false]));
    }

    public function testServiceAsObject(): void
    {
        $builder = $this->getContainer();
        $builder->set('foo', $foo = new \stdClass());

        $this->assertEquals($foo, $builder->definition('foo'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('foo'));
    }

    public function testEmptyContainer(): void
    {
        $builder = $this->getContainer();
        $this->assertStringEqualsFile(self::COMPILED . '/service2.phpt', $builder->compile(['containerClass' => 'EmptyContainer']));
    }

    public function testDefinitionClassWithProvidedArguments(): void
    {
        $builder = $this->getContainer();
        \class_alias(Fixtures\Service::class, 'NonExistent');

        $builder->set('bar', new Definition(Fixtures\Bar::class))->args(['NonExistent' => new Statement('NonExistent'), 'value', 'foo' => [1, 2, 3]]);
        $this->assertStringEqualsFile(self::COMPILED . '/service3_' . ((int) \PHP_MAJOR_VERSION >= 8) . '.phpt', $builder->compile());
    }
}
