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

namespace Rade\DI\Config;

use Rade\DI\AbstractContainer;

abstract class AbstractConfiguration implements ConfigurationInterface
{
    protected array $config = [];

    /**
     * {@inheritdoc}
     */
    abstract public function getId(): string;

    /**
     * {@inheritdoc}
     */
    public function setConfiguration(array $config, AbstractContainer $container): void
    {
        $this->config = $config;
        //@Todo: this method can be overridden to use $container.
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(): array
    {
        if ([] === $this->config) {
            throw new \RuntimeException('Configurations for this provider is empty. See "setConfiguration" method.');
        }

        return $this->config;
    }
}
