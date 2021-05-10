<?php

declare (strict_types=1);

/**
 * @internal This class has been auto-generated by the Rade DI.
 */
class RawContainer extends Rade\DI\Container
{
    public array $parameters = [];

    protected static array $privates = [];

    protected array $methodsMap = ['raw' => 'getRaw', 'service1' => 'getService1', 'container' => 'getServiceContainer'];

    protected array $types = [Rade\DI\AbstractContainer::class => ['container'], Psr\Container\ContainerInterface::class => ['container'], Rade\DI\Container::class => ['container']];

    protected array $aliases = [];

    protected function getRaw()
    {
        return 123;
    }

    protected function getService1(): Rade\DI\Tests\Fixtures\Service
    {
        $service = new Rade\DI\Tests\Fixtures\Service();
        $service->value = 123;
        
        return self::$services['service1'] = $service;
    }
}
