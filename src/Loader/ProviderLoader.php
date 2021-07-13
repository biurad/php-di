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

namespace Rade\DI\Loader;

use Rade\DI\{AbstractContainer, Container, ContainerBuilder};
use Rade\DI\Services\ServiceProviderInterface;

/**
 * This class delegates container service providers.
 * Setting service provider's config, keys should always begin with service provider's class name.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ProviderLoader
{
    /** @var ServiceProviderInterface[] */
    private array $providers;

    private array $config;

    /**
     * @param ServiceProviderInterface[] $providers
     */
    public function __construct(array $providers, array $config = [])
    {
        $this->providers = $providers;
        $this->config = $config;
    }

    /**
     * Loads service providers will one configuration.
     *
     * @param Container|ContainerBuilder $container
     */
    public function load(AbstractContainer $container): void
    {
        foreach ($this->providers as $provider) {
            $container->register($provider, $this->config[\get_class($provider)] ?? []);
        }
    }
}
