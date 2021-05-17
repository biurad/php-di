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

class Bar implements BarInterface
{
    public $quz;

    public function __construct($quz = null, \NonExistent $nonExistent = null, BarInterface $decorated = null, array $foo = [], iterable $baz = [])
    {
        $this->quz = $quz;
    }

    public static function create(\NonExistent $nonExistent = null, $factory = null)
    {
    }
}
