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

namespace Rade\DI\Builder;

use Rade\DI\ContainerBuilder;
use Rade\DI\Services\ConfigurationInterface;

/**
 * This class delegates container builder extensions.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ExtensionLoader
{
    /** @var ExtensionInterface[] */
    private array $extensions;

    private array $config;

    /**
     * @param ExtensionInterface[] $extensions
     */
    public function __construct(array $extensions, array $config = [])
    {
        $this->extensions = $extensions;
        $this->config = $config;
    }

    /**
     * Loads service extensions will one configuration.
     *
     * @param array $options passed to builder's compile method
     *
     * @return \PhpParser\Node[]|string
     */
    public function load(ContainerBuilder $builder, array $options = [])
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof ConfigurationInterface) {
                $builder->register($extension, $this->config[$extension->getId()] ?? []);

                continue;
            }

            $builder->register($extension);
        }

        return $builder->compile($options);
    }
}
