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
    /** @final The reserved service id for container's instance */
    public const SERVICE_CONTAINER = 'container';

    /** Sets the behaviour to ignore exception on types with multiple services */
    public const IGNORE_MULTIPLE_SERVICE = 0;

    /** Set a strict behaviour to thrown an exception on types with multiple services */
    public const EXCEPTION_ON_MULTIPLE_SERVICE = 1;

    /** Instead of throwing an exception, null will return if service not found */
    public const NULL_ON_INVALID_SERVICE = 2;

    /**
     * Set a service definition.
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @return Definition|Definitions\ValueDefinition or DefinitionInterface, mixed value which maybe object
     */
    public function set(string $id, object $definition = null): object;

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
     * Returns true if the given service has actually been initialized.
     *
     * @param string $id The service identifier
     *
     * @return bool true if service has already been initialized, false otherwise
     */
    public function initialized(string $id): bool;

    /**
     * Sets multiple definitions at once into the container.
     *
     * @param array<int|string,mixed> $definitions indexed by their ids
     */
    public function multiple(array $definitions): void;

    /**
     * Gets/Extends a service definition from the container by its id.
     *
     * @param string $id service id relying on this definition
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function definition(string $id);

    /**
     * Marks an alias id to service id.
     *
     * @param string $id        The alias id
     * @param string $serviceId The registered service id
     *
     * @throws NotFoundServiceException Service id is not found in container
     */
    public function alias(string $id, string $serviceId): void;

    /**
     * Checks if a service definition has been aliased.
     *
     * @param string $id The registered service id
     */
    public function aliased(string $id): bool;
}
