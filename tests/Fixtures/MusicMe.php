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

use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Rade\DI\Container;

class MusicMe
{
    public $g;
    public $cool;
    public $allowed;
    public $name;

    /**
     * @param string                           $cool
     * @param string[]                         $g
     * @param ArgumentValueResolverInterface[] $allowed
     * @param NamedValueResolver               $named
     */
    public function __construct($cool, $g = [], array $allowed = [], ArgumentValueResolverInterface $named = null)
    {
        $this->cool = $cool;
        $this->g = $g;
        $this->allowed = $allowed;
        $this->name = $named;
    }

    public static function something(): Service
    {
        return new Constructor(new Container());
    }

    public function variadic(ArgumentValueResolverInterface ...$values)
    {
        return $values;
    }
}
