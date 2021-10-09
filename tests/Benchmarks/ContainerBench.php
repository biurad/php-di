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

use DivineNii\Invoker\ArgumentResolver\NamedValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Tests\Fixtures\Constructor;
use Rade\DI\Tests\Fixtures\Service;
use Symfony\Component\Filesystem\Filesystem;

use function Rade\DI\Loader\service;

/**
 * @BeforeClassMethods({"clearCache", "warmup"})
 * @AfterClassMethods({"tearDown"})
 * @Iterations(5)
 * @Revs(100)
 */
class ContainerBench
{
    public const SERVICE_COUNT = 200;

    protected const CACHE_DIR = __DIR__ . '/../cache';

    private Container $container;

    public function provideDefinitions(): iterable
    {
        yield 'Shared' => ['shared'];
        yield 'Factory' => ['factory'];

        yield 'Shared,Autowired' => ['shared_autowired'];
        yield 'Factory,Autowired' => ['factory_autowired'];

        yield 'Shared,Typed,Autowired' => [0, Service::class];
        yield 'Factory,Typed,Autowired' => [0, ArgumentValueResolverInterface::class];
    }

    public static function clearCache(): void
    {
        self::tearDown();
        \mkdir(self::CACHE_DIR);
    }

    public static function tearDown(): void
    {
        if (\file_exists(self::CACHE_DIR)) {
            $fs = new Filesystem();
            $fs->remove(self::CACHE_DIR);
        }
    }

    public static function warmup(bool $dump = true): void
    {
        $builder = new ContainerBuilder();

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $builder->set("shared$i", service(Service::class));
            $builder->set("factory$i", service(Service::class))->shared(false);

            $builder->autowire("shared_autowired$i", service(Constructor::class));
            $builder->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);
        }

        if ($dump) {
            \file_put_contents(self::CACHE_DIR . '/container.php', $builder->compile());
        }
    }

    public function createOptimized(): void
    {
        include_once self::CACHE_DIR . \DIRECTORY_SEPARATOR . 'container.php';

        $this->container = new \CompiledContainer();
    }

    public function benchConstruct(): void
    {
        $container = new Container();

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $container->set("shared$i", new Service());
            $container->set("factory$i", service(Service::class))->shared(false);

            $container->autowire("shared_autowired$i", service(Constructor::class));
            $container->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);
        }

        $this->container = $container;
    }

    public function benchConstructWithMultiple(): void
    {
        $definitions = [];

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions["shared$i"] = new Service();
            $definitions["factory$i"] = service(Service::class)->shared(false);

            $definitions["shared_autowired$i"] = service(Constructor::class);
            $definitions["factory_autowired$i"] = service(NamedValueResolver::class)->shared(false);
        }

        $container = new Container();
        $container->multiple($definitions);
    }

    public function benchContainerBuilder(): void
    {
        self::warmup(false);
    }

    public function benchContainerBuilderWithMultiple(): void
    {
        $definitions = [];

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions["shared$i"] = service(Service::class);
            $definitions["factory$i"] = service(Service::class)->shared(false);

            $definitions["shared_autowired$i"] = service(Constructor::class);
            $definitions["factory_autowired$i"] = service(NamedValueResolver::class)->shared(false);
        }

        $builder = new ContainerBuilder();
        $builder->multiple($definitions);
    }

    /**
     * @BeforeMethods({"createOptimized"}, extend=true)
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchOptimizedGet(array $params): void
    {
        if (isset($params[1])) {
            $this->container->get($params[1], Container::IGNORE_MULTIPLE_SERVICE);

            return;
        }

        for ($i = 0; $i < self::SERVICE_COUNT / 2; ++$i) {
            $this->container->get($params[0] . $i);
        }
    }

    /**
     * @BeforeMethods({"benchConstruct"}, extend=true)
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchUnoptimizedGet(array $params): void
    {
        if (isset($params[1])) {
            $this->container->get($params[1], Container::IGNORE_MULTIPLE_SERVICE);

            return;
        }

        for ($i = 0; $i < self::SERVICE_COUNT / 2; ++$i) {
            $this->container->get($params[0] . $i);
        }
    }

    /**
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchOptimisedLifecycle(array $params): void
    {
        $this->createOptimized();
        $this->container->get($params[1] ?? ($params[0] . \rand(0, 199)), Container::IGNORE_MULTIPLE_SERVICE);
    }

    /**
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchUnoptimisedLifecycle(array $params): void
    {
        $this->benchConstruct();
        $this->container->get($params[1] ?? ($params[0] . \rand(0, 199)), Container::IGNORE_MULTIPLE_SERVICE);
    }
}
