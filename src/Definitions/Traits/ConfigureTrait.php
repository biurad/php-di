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

namespace Rade\DI\Definitions\Traits;

use Nette\Utils\Validators;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\{BooleanNot, Instanceof_, Variable};
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\{If_, Throw_};
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\ServiceCreationException;

/**
 * This trait adds instance of functionality to the service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ConfigureTrait
{
    /** @var string|null Expect value to be a valid class|interface|enum|trait type. */
    private ?string $instanceOf = null;

    /**
     * If set and service entity not instance of, an exception will be thrown.
     *
     * If value is a valid class, interface, enum or trait type, an instance of type checking is used,
     * else value will be perceived to be a file or directory.
     *
     * @param string $instanceOf expect value to be a valid class|interface|enum|trait type
     *                           or either file or directory
     *
     * @return $this
     */
    public function configure(string $instanceOf)
    {
        $this->instanceOf = $instanceOf;

        return $this;
    }

    /**
     * Triggers a validation on instance of rule.
     *
     * @param Variable|object $service
     */
    public function triggerInstanceOf(string $id, object $service, BuilderFactory $builder = null): ?If_
    {
        $errorMessage = \sprintf('Service with id: "%s" depends on "%s".', $id, $this->instanceOf);

        if (null === $builder) {
            if (\is_subclass_of($service, $this->instanceOf) || \file_exists($this->instanceOf)) {
                return null;
            }

            throw new ServiceCreationException($errorMessage);
        }

        if (Validators::isType($this->instanceOf)) {
            $errorInstance = new Instanceof_($service, new Name($this->instanceOf));
        }

        return new If_(
            new BooleanNot($errorInstance ?? $builder->funcCall('file_exists', [$this->instanceOf])),
            ['stmts' => [new Throw_($builder->new(ContainerResolutionException::class, [$errorMessage])), ]]
        );
    }
}
