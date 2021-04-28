<?php

declare (strict_types=1);

/**
 * @internal This class has been auto-generated by the Rade DI.
 */
class CompiledContainer extends Rade\DI\Container
{
    public array $parameters = [];

    protected static array $privates = [];

    protected array $methodsMap = ['autowired' => 'getAutowired', 'service_1' => 'getService1', 'statement' => 'getStatement', 'container' => 'getServiceContainer'];

    protected array $types = [Rade\DI\AbstractContainer::class => ['container'], Psr\Container\ContainerInterface::class => ['container'], Rade\DI\Container::class => ['container'], Rade\DI\Tests\Fixtures\Service::class => ['autowired']];

    protected array $aliases = [];

    protected function getAutowired(): Rade\DI\Tests\Fixtures\Service
    {
        return self::$services['autowired'] = new Rade\DI\Tests\Fixtures\Service();
    }

    protected function getService1(): Rade\DI\Tests\Fixtures\Service
    {
        return self::$services['service_1'] = new Rade\DI\Tests\Fixtures\Service();
    }

    protected function getStatement(): Rade\DI\Tests\Fixtures\Constructor
    {
        return self::$services['statement'] = new Rade\DI\Tests\Fixtures\Constructor($this);
    }
}