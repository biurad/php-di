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

namespace Rade\DI\Extensions;

use Rade\DI\{Container, ContainerBuilder};

/**
 * PHP directives definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class PhpExtension implements AliasedInterface, BootExtensionInterface, ExtensionInterface
{
    /** @var array<string,string> */
    private array $config = [];

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'php';
    }

    /**
     * Set a config supported by this extension.
     *
     * @param mixed $value
     */
    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function register(Container $container, array $configs): void
    {
        $this->config = $configs;
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Container $container): void
    {
        if (!$container instanceof ContainerBuilder) {
            foreach ($this->config as $name => $value) {
                if (null === $value) {
                    continue;
                }

                if ('include_path' === $name) {
                    \set_include_path(\str_replace(';', \PATH_SEPARATOR, $value));
                } elseif ('ignore_user_abort' === $name) {
                    \ignore_user_abort(null === $value ? $value : (bool) $value);
                } elseif ('max_execution_time' === $name) {
                    \set_time_limit((int) $value);
                } elseif ('date.timezone' === $name) {
                    \date_default_timezone_set($value);
                } elseif (\function_exists('ini_set')) {
                    \ini_set($name, false === $value ? '0' : (string) $value);
                } elseif (\ini_get($name) !== (string) $value) {
                    throw new \InvalidArgumentException('Required function ini_set() is disabled.');
                }
            }
        }
    }
}
