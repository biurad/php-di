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

/**
 * This class delegates container service providers.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ProviderLoader
{
    /** @var Services\ServiceProviderInterface[] */
    private array $providers;

    private array $config;

    /**
     * @param Services\ServiceProviderInterface[] $providers
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
    public function load(AbstractContainer $container)
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof Services\ConfigurationInterface) {
                $config = $this->config[$provider->getId()] ?? [];
            }

            $container->register($provider, $config ?? []);
        }

        return $container;
    }
}
