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

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Rade\DI\Definitions\DefinitionInterface;
use Rade\DI\Exceptions\{CircularReferenceException, NotFoundServiceException};

/**
 * ContainerInterface is the interface implemented by service container classes.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ContainerInterface extends PsrContainerInterface
{
    /** @final The name of the default container's service */
    public const SERVICE_CONTAINER = 'container';

    /** Sets the behaviour to ignore exception on types with multiple services */
    public const IGNORE_MULTIPLE_SERVICE = 0;

    /** Set a strict behaviour to thrown an exception on types with multiple services */
    public const EXCEPTION_ON_MULTIPLE_SERVICE = 1;

    /** Instead of throwing an exception, null will return if service not found */
    public const NULL_ON_INVALID_SERVICE = 2;

    /** This prevents registered definition from be replace, but can be shared */
    public const IGNORE_SERVICE_FREEZING = 3;

    /**
     * Set a service definition.
     *
     * @param DefinitionInterface|string|object|null $definition
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function set(string $id, $definition);

    /**
     * {@inheritdoc}
     *
     * @throws CircularReferenceException When a circular reference is detected
     * @throws NotFoundServiceException   When the service is not defined
     */
    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_MULTIPLE_SERVICE);

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool;

    /**
     * Returns all defined service definitions identifiers.
     *
     * @return array<int,string>
     */
    public function keys(): array;

    /**
     * Returns true if the given service has actually been initialized.
     *
     * @param string $id The service identifier
     *
     * @return bool true if service has already been initialized, false otherwise
     */
    public function initialized(string $id): bool;

    /**
     * Extends a service definition.
     *
     * @param string        $id    The unique identifier for the object
     * @param callable|null $scope A service definition to extend the original
     *
     * @throws NotFoundServiceException If the identifier is not defined
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function extend(string $id, callable $scope = null);

    /**
     * Sets multiple definitions at once into the container.
     *
     * @param array<int|string,mixed> $definitions indexed by their ids
     */
    public function multiple(array $definitions): void;

    /**
     * Gets the service definition or aliased entry from the container.
     *
     * @param string $id service id relying on this definition
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function definition(string $id);
}
