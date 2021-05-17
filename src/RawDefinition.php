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

namespace Rade\DI;

use PhpParser\BuilderFactory;
use Rade\DI\Exceptions\ContainerResolutionException;

/**
 * Represents a service which should not be resolved.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class RawDefinition
{
    /** @var mixed */
    private $service;

    /**
     * @param mixed $service
     */
    public function __construct($service)
    {
        if ($service instanceof self) {
            throw new ContainerResolutionException('unresolvable definition cannot contain itself.');
        }

        $this->service = $service;
    }

    /**
     * @return mixed $service
     */
    public function __invoke()
    {
        return $this->service;
    }

    /**
     * Build the raw definition service.
     */
    public function build(string $id, BuilderFactory $builder): \PhpParser\Builder\Method
    {
        return $builder->method(Definition::createMethod($id))->makeProtected()
            ->addStmt(new \PhpParser\Node\Stmt\Return_($builder->val($this->service)));
    }
}
