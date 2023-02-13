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

use Rade\DI\Attribute\Inject;
use Rade\DI\Attribute\Tagged;
use Rade\DI\Injector\InjectableInterface;

/**
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ClassWithInject implements InjectableInterface
{
    #[Inject('?bar')] public ?Constructor $service;

    public function getService(): Service
    {
        return $this->service;
    }

    public static function InjectParameter(#[Inject('container')] $container, #[Inject] FooClass $foo)
    {
        return [$container, $foo];
    }

    public static function injectTag(#[Tagged('a')] array $tagged)
    {
        return $tagged;
    }
}
