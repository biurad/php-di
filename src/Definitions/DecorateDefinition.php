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

namespace Rade\DI\Definitions;

use Rade\DI\Definition;

class DecorateDefinition
{
    /** @var array<int,DefinitionInterface|object> */
    private $definitions;

    /**
     * @param array<int,Definition> $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    /**
     * Using a callable to decorate service definitions.
     *
     * @param callable $decorator takes the definition service instance as argument
     *
     * @return $this
     */
    public function bind(callable $decorator): self
    {
        foreach ($this->definitions as $definition) {
            $decorator($definition);
        }

        return $this;
    }

    /**
     * If definitions are instance of Definition class, bind the definition's
     * valid method and valid arguments as attribute.
     *
     * @param mixed $attribute
     */
    public function definition(string $defMethod, $attribute): self
    {
        foreach ($this->definitions as $definition) {
            if ($definition instanceof Definition) {
                \call_user_func_array([$definition, $defMethod], \is_array($attribute) ? $attribute : [$attribute]);
            }
        }

        return $this;
    }
}
