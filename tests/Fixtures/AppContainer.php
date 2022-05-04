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

namespace Rade\DI\Tests\Fixtures;

use Rade\DI\Container;
use Rade\DI\Resolver;

class AppContainer extends Container
{
    protected array $aliases = ['alias' => 'bar'];

    public function __construct()
    {
        parent::__construct();
        $this->types += [BarInterface::class => ['foo_baz']];
    }

    public function has(string $id): bool
    {
        return \method_exists($this, Resolver::createMethod($this->aliases[$id] ?? $id)) || parent::has($id);
    }

    public function get(string $id, int $invalidBehavior = 1)
    {
        switch ($id = $this->aliases[$id] ?? $id) {
            case 'bar':
                return $this->services[$id] ?? $this->getBar();
            case self::SERVICE_CONTAINER:
                return $this;
            case 'foo_baz':
                return $this->services[$id] ?? $this->getFooBaz();
            case 'foo_bar':
                return $this->getFooBar();
            case 'foo.baz':
                return $this->getFoo_Baz();
            case 'throw_exception':
                throw new \RuntimeException('Error');
            case 'throws_exception_on_service_configuration':
                return $this->services[$id] ?? $this->getThrowsExceptionOnServiceConfiguration();
            case 'internal_dependency':
                return $this->services[$id] ?? $this->getInternalDependency();
        }

        return $this->doLoad($id, $invalidBehavior);
    }

    protected function getInternal(): object
    {
        return $this->privates['internal'] = new \stdClass();
    }

    protected function getBar(): object
    {
        return $this->services['bar'] = new \stdClass();
    }

    protected function getFooBar(): object
    {
        return new \stdClass();
    }

    protected function getFooBaz(): Bar
    {
        return $this->services['foo_baz'] = new Bar('a', null, new Bar('hello'), ['joo']);
    }

    protected function getFoo_Baz(): object
    {
        return new \stdClass();
    }

    protected function getCircular()
    {
        return $this->get('circular');
    }

    protected function getThrowsExceptionOnServiceConfiguration(): void
    {
        $this->services['throws_exception_on_service_configuration'] = new \stdClass();

        throw new \Exception('Something was terribly wrong while trying to configure the service!');
    }

    protected function getInternalDependency(): object
    {
        $service = new \stdClass();
        $service->internal = $this->privates['internal'] ?? $this->getInternal();

        return $this->services['internal_dependency'] = $service;
    }
}
