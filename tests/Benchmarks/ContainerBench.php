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
use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\DefinitionBuilder;
use Rade\DI\SealedContainer;
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

    private ContainerInterface $container;

    public function provideDefinitions(): iterable
    {
        yield 'Shared' => ['shared'];

        yield 'Factory' => ['factory'];

        yield 'Shared,Autowired' => ['shared_autowired'];

        yield 'Factory,Autowired' => ['factory_autowired'];

        yield 'Shared,Typed,Autowired' => [null, Service::class];

        yield 'Factory,Typed,Autowired' => [null, ArgumentValueResolverInterface::class];
    }

    public static function clearCache(): void
    {
        self::tearDown();

        if (!\is_dir(self::CACHE_DIR)) {
            \mkdir(self::CACHE_DIR);
        }
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

        if ($dump) {
            $sealed = new ContainerBuilder(SealedContainer::class);
        }

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $builder->set("shared$i", service(Service::class));
            $builder->set("factory$i", service(Service::class))->shared(false);

            $builder->autowire("shared_autowired$i", service(Constructor::class));
            $builder->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);

            if (isset($sealed)) {
                $sealed->set("shared$i", service(Service::class));
                $sealed->set("factory$i", service(Service::class))->shared(false);

                $sealed->autowire("shared_autowired$i", service(Constructor::class));
                $sealed->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);
            }
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

    public function benchConstructWithMultiple(bool $set = false): void
    {
        $definitions = [];

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions["shared$i"] = new Service();
            $definitions["factory$i"] = service(Service::class)->shared(false);

            $definitions["shared_autowired$i"] = service(Constructor::class)->autowire();
            $definitions["factory_autowired$i"] = service(NamedValueResolver::class)->autowire()->shared(false);
        }

        $container = new Container();
        $container->multiple($definitions);

        if ($set) {
            $this->container = $container;
        }
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

            $definitions["shared_autowired$i"] = service(Constructor::class)->autowire();
            $definitions["factory_autowired$i"] = service(NamedValueResolver::class)->autowire()->shared(false);
        }

        $builder = new ContainerBuilder();
        $builder->multiple($definitions);
    }

    public function benchContainerWithDefinitionBuilder(bool $set = false): void
    {
        $definitions = new DefinitionBuilder(new Container());

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions
                ->set("shared$i", service(Service::class))
                ->set("factory$i", service(Service::class))->shared(false)

                ->autowire("shared_autowired$i", service(Constructor::class))
                ->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);
        }

        if ($set) {
            $this->container = $definitions->getContainer();
        }
    }

    public function benchContainerBuilderWithDefinitionBuilder(): void
    {
        $definitions = new DefinitionBuilder(new ContainerBuilder());

        for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
            $definitions
                ->set("shared$i", service(Service::class))
                ->set("factory$i", service(Service::class))->shared(false)

                ->autowire("shared_autowired$i", service(Constructor::class))
                ->autowire("factory_autowired$i", service(NamedValueResolver::class))->shared(false);
        }

        $definitions->getContainer();
    }

    /**
     * @BeforeMethods({"createOptimized"})
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchOptimizedGet(array $params): void
    {
        if (isset($params[1])) {
            $this->container->get($params[1], Container::IGNORE_MULTIPLE_SERVICE);
        } else {
            for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
                $this->container->get($params[0] . $i);
            }
        }
    }

    /**
     * @BeforeMethods({"benchConstruct"})
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchUnoptimizedGet(array $params): void
    {
        if (isset($params[1])) {
            $this->container->get($params[1], Container::IGNORE_MULTIPLE_SERVICE);
        } else {
            for ($i = 0; $i < self::SERVICE_COUNT; ++$i) {
                $this->container->get($params[0] . $i);
            }
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

    /**
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchUnoptimisedMultipleLifecycle(array $params): void
    {
        $this->benchConstructWithMultiple(true);
        $this->container->get($params[1] ?? ($params[0] . \rand(0, 199)), Container::IGNORE_MULTIPLE_SERVICE);
    }

    /**
     * @ParamProviders({"provideDefinitions"})
     */
    public function benchUnoptimisedBuilderLifecycle(array $params): void
    {
        $this->benchContainerWithDefinitionBuilder(true);
        $this->container->get($params[1] ?? ($params[0] . \rand(0, 199)), Container::IGNORE_MULTIPLE_SERVICE);
    }
}
