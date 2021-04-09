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

namespace Rade\DI\Services;

use Psr\Container\ContainerInterface;
use Symfony\Component\Config\Definition\ConfigurationInterface as ConfigContextInterface;

/**
 * Declares that service provider has configurations.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ConfigurationInterface extends ConfigContextInterface
{
    /**
     * The unique name of the service provider in finding
     * configurations belonging to this provider in container's $parameters.
     */
    public function getName(): string;

    /**
     * Sets service's provider or builder configuration.
     *
     * @param ContainerInterface $container either ContainerBuilder or Container instance.
     */
    public function setConfiguration(array $config, ContainerInterface $container): void;

    /**
     * Returns service's provider or builder configuration.
     *
     * @return array of resolved configurations
     */
    public function getConfiguration(): array;
}
