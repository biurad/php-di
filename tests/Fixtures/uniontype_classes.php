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

class UnionScalars
{
    public function __construct(int|float $timeout)
    {
    }
}

class UnionClasses
{
    public function __construct(CollisionA|CollisionB $collision)
    {
    }
}

class UnionNull
{
    public function __construct(private CollisionInterface|null $c)
    {
    }
}

class CollisionA
{

}

class CollisionB
{

}

interface CollisionInterface
{

}

$unionFunction = fn (CollisionB|CollisionA $collision): CollisionA|CollisionB => $collision;
