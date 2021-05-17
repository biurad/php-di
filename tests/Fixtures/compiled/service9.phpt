<?php

protected function getBar(): Rade\DI\Tests\Fixtures\Bar
{
    $service = new Rade\DI\Tests\Fixtures\Bar(null, null, null, [], []);
    $service->quz = 'quz';
    $factory = [1, 2, 3, 4, 'value'];
    $service->create(null, $factory);

    return self::$services['bar'] = $service;
}

protected function getRadeDITestsFixturesBar()
{
    $service = [self::$services['Rade\\DI\\Tests\\Fixtures\\Bar'] ?? $this->getRadeDITestsFixturesBar(), 'create']();

    return self::$services['Rade\\DI\\Tests\\Fixtures\\Bar'] = $service;
}
