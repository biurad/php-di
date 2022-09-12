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

namespace Rade\DI\Tests\Benchmarks;

use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\DefinitionBuilder;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Tests\Fixtures\Constructor;
use Rade\DI\Tests\Fixtures\Service;
use DivineNii\Invoker\ArgumentResolver\NamedValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

use function Rade\DI\Loader\service;

/**
 * @BeforeClassMethods({"clearCache"})
 * @AfterClassMethods({"clearCache"})
 * @Iterations(5)
 * @Revs(100)
 */
class ContainerBench
{
    public const SERVICE_COUNT = 1500;

    protected const CACHE_DIR = __DIR__ . '/../cache';

    /** @var array<string,ContainerInterface> */
    private array $containers = [];

    public static function clearCache(): void
    {
        if (\file_exists(self::CACHE_DIR)) {
            $fs = new Filesystem();
            $fs->remove(self::CACHE_DIR);
        }

        \mkdir(self::CACHE_DIR, 0777, true);
    }

    public function provideContainers(): iterable
    {
        yield 'container' => ['use' => 'container'];

        yield 'builder' => ['use' => 'builder'];
    }

    public function provideScenarios(): iterable
    {
        yield 'first_shared' => [
            'id' => 'shared0',
            'result' => Service::class,
        ];

        yield 'first_factory' => [
            'id' => 'factory0',
            'result' => Service::class,
        ];

        yield 'first_shared_autowired' => [
            'id' => 'shared_autowired0',
            'result' => Service::class,
        ];

        yield 'first_factory_autowired' => [
            'id' => 'factory_autowired0',
            'result' => ArgumentValueResolverInterface::class,
        ];

        yield 'middle_shared' => [
            'id' => 'shared' . self::SERVICE_COUNT / 2,
            'result' => Service::class,
        ];

        yield 'middle_factory' => [
            'id' => 'factory' . self::SERVICE_COUNT / 2,
            'result' => Service::class,
        ];

        yield 'middle_shared_autowired' => [
            'id' => 'shared_autowired' . self::SERVICE_COUNT / 2,
            'result' => Service::class,
        ];

        yield 'middle_factory_autowired' => [
            'id' => 'factory_autowired' . self::SERVICE_COUNT / 2,
            'result' => ArgumentValueResolverInterface::class,
        ];

        yield 'last_shared' => [
            'id' => 'shared1499',
            'result' => Service::class,
        ];

        yield 'last_factory' => [
            'id' => 'factory1499',
            'result' => Service::class,
        ];

        yield 'last_shared_autowired' => [
            'id' => 'shared_autowired1499',
            'result' => Service::class,
        ];

        yield 'last_factory_autowired' => [
            'id' => 'factory_autowired1499',
            'result' => ArgumentValueResolverInterface::class,
        ];

        yield 'typed_shared' => [
            'id' => Service::class,
            'flag' => Container::IGNORE_MULTIPLE_SERVICE,
            'result' => 1499,
        ];

        yield 'typed_factory' => [
            'id' => ArgumentValueResolverInterface::class,
            'flag' => Container::IGNORE_MULTIPLE_SERVICE,
            'result' => 1499,
        ];

        yield 'aliased_shared' => [
            'id' => 'a_shared',
            'result' => Service::class,
        ];

        yield 'aliased_factory' => [
            'id' => 'a_factory',
            'result' => Service::class,
        ];

        yield 'non-existent' => [
            'id' => 'none',
            'result' => NotFoundExceptionInterface::class,
        ];
    }

    public function provideAllScenarios(): iterable
    {
        yield 'scenarios(first,middle,last,typed,aliased,none)' => \array_values(\iterator_to_array($this->provideScenarios()));
        $all = [];

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $all[] = [
                'id' => 'shared' . $i,
                'result' => Service::class,
            ];
            $all[] = [
                'id' => 'factory' . $i,
                'result' => Service::class,
            ];
            $all[] = [
                'id' => 'shared_autowired' . $i,
                'result' => Service::class,
            ];
            $all[] = [
                'id' => 'factory_autowired' . $i,
                'result' => ArgumentValueResolverInterface::class,
            ];
        }
        $all[] = [
            'id' => Service::class,
            'flag' => Container::IGNORE_MULTIPLE_SERVICE,
            'result' => 999,
        ];
        $all[] = [
            'id' => ArgumentValueResolverInterface::class,
            'flag' => Container::IGNORE_MULTIPLE_SERVICE,
            'result' => 999,
        ];
        $all[] = [
            'id' => 'a_shared',
            'result' => Service::class,
        ];
        $all[] = [
            'id' => 'a_factory',
            'result' => Service::class,
        ];
        $all[] = [
            'id' => 'none',
            'result' => NotFoundExceptionInterface::class,
        ];

        yield 'scenarios(0...999,typed,aliased,none)' => $all;
    }

    public function initContainers(): void
    {
        $this->containers['container'] = $this->createContainer();
        $this->containers['builder'] = $this->createBuilder();
    }

    public function createContainer(): ContainerInterface
    {
        $container = new Container();

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $container->set("shared{$i}", new Service());
            $container->set("factory{$i}", service(Service::class))->shared(false);

            if (25 === $i) {
                $container->alias('a_shared', 'shared25');
                $container->alias('a_factory', 'factory25');
            }

            $container->autowire("shared_autowired{$i}", service(Constructor::class));
            $container->autowire("factory_autowired{$i}", service(NamedValueResolver::class))->shared(false);
        }

        return $container;
    }

    public function createDefContainer(): ContainerInterface
    {
        $definitions = new DefinitionBuilder(new Container());

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions
                ->set("shared{$i}", service(Service::class))
                ->set("factory{$i}", service(Service::class))->shared(false)

                ->if(static fn (): bool => 25 === $i)
                    ->alias('a_shared', 'shared25')
                    ->alias('a_factory', 'factory25')
                ->endIf()

                ->autowire("shared_autowired{$i}", service(Constructor::class))
                ->autowire("factory_autowired{$i}", service(NamedValueResolver::class))->shared(false);
        }

        return $definitions->getContainer();
    }

    public function createBuilder(): ContainerInterface
    {
        if (!\file_exists($f = self::CACHE_DIR . '/container.php')) {
            $builder = new ContainerBuilder();

            for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
                $builder->set("shared{$i}", service(Service::class));
                $builder->set("factory{$i}", service(Service::class))->shared(false);

                if (25 === $i) {
                    $builder->alias('a_shared', 'shared25');
                    $builder->alias('a_factory', 'factory25');
                }

                $builder->autowire("shared_autowired{$i}", service(Constructor::class));
                $builder->autowire("factory_autowired{$i}", service(NamedValueResolver::class))->shared(false);
            }
            \file_put_contents($f, $builder->compile());
        }

        include_once $f;

        return new \CompiledCOntainer();
    }

    /**
     * @BeforeMethods({"initContainers"})
     * @ParamProviders({"provideContainers", "provideScenarios"})
     */
    public function benchScenarios(array $params): void
    {
        $this->runScenario($params);
    }

    /**
     * @BeforeMethods({"initContainers"})
     * @ParamProviders({"provideContainers", "provideAllScenarios"})
     */
    public function benchAllScenarios(array $params): void
    {
        $use = \array_shift($params);

        foreach ($params as $param) {
            $this->runScenario($param + \compact('use'));
        }
    }

    /**
     * @ParamProviders({"provideAllScenarios"})
     * @Revs(4)
     */
    public function benchContainer(array $params): void
    {
        $this->containers['1'] = $this->createContainer();

        foreach ($params as $param) {
            $this->runScenario($param + ['use' => '1']);
        }
    }

    /**
     * @ParamProviders({"provideAllScenarios"})
     * @Revs(4)
     */
    public function benchDefContainer(array $params): void
    {
        $this->containers['2'] = $this->createDefContainer();

        foreach ($params as $param) {
            $this->runScenario($param + ['use' => '2']);
        }
    }

    /**
     * @ParamProviders({"provideAllScenarios"})
     * @Revs(4)
     */
    public function benchBuilder(array $params): void
    {
        $this->containers['3'] = $this->createBuilder();

        foreach ($params as $param) {
            $this->runScenario($param + ['use' => '3']);
        }
    }

    /**
     * @param array<string,array<int,mixed>|string> $params
     */
    private function runScenario(array $params): void
    {
        try {
            $container = $this->containers[$params['use']];
            $service = $container->get($params['id'], $params['flag'] ?? 1);
            $result = !isset($params['flag']) ? \is_a($service, $params['result']) : $params['result'] === \count($service);
        } catch (NotFoundServiceException $e) {
            $result = \is_a($e, $params['result']);
        }

        \assert($result, new \RuntimeException(
            \sprintf('Benchmark "%s" failed with service id "%s"', $params['use'], $params['id'])
        ));
    }
}
