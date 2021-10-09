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

namespace Rade\DI\Traits;

use Rade\DI\Services\{AliasedInterface, DependenciesInterface, ServiceProviderInterface};
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};

/**
 * This trait allows container to be extended using a service provider.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ProviderTrait
{
    /** @var array<string,ServiceProviderInterface> A list of service providers */
    protected array $providers = [];

    /**
     * Returns the registered service provider.
     *
     * @param string $id The class name of the service provider
     */
    public function provider(string $id, bool $extendable = false): ?ServiceProviderInterface
    {
        $provider = $this->providers[$id] ?? null;

        if (null === $provider) {
            return null; // Disallow setting null providers on extendable mode.
        }

        return $extendable ? $this->providers[$id] = $provider : $provider;
    }

    /**
     * Gets all service providers.
     *
     * @return array<string,Definitions\DefinitionInterface>
     */
    {
        return $this->providers;
    }

    /**
     * Remove a service provider.
     */
    {
        unset($this->providers[$provider]);
    }

    /**
     * Registers a service provider.
     *
     * @param ServiceProviderInterface $provider A ServiceProviderInterface instance
     * @param array<int|string,mixed>  $config   An array of config that customizes the provider
     */
    public function register(ServiceProviderInterface $provider, array $config = []): self
    {
        $this->providers[$providerId = \get_class($provider)] = $provider;

        if ($provider instanceof DependenciesInterface) {
            $this->registerDependents($provider, $config);
        }

        if ($provider instanceof ConfigurationInterface) {
            if ($provider instanceof AliasedInterface) {
                $providerId = $provider->getAlias();
            }

            $config = (new Processor())->processConfiguration($provider, [$providerId => $config]);
        }

        $provider->register($this, $config);

        return $this;
    }

    protected function registerDependents(DependenciesInterface $provider, array $config): void
    {
        foreach ($provider->dependencies() as $offset => $dependency) {
            if (\is_string($dependency)) {
                $dependency = new $dependency();
            }

            if ($dependency instanceof ServiceProviderInterface) {
                if (\is_numeric($offset) && $dependency instanceof AliasedInterface) {
                    $dependencyConfig = $config[$dependency->getAlias()] ?? [];
                } elseif (\is_string($offset)) {
                    $dependencyConfig = $config[$offset] ?? [];
                }

                $this->register($dependency, $dependencyConfig ?? []);
            }
        }
    }
}
