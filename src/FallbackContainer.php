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

use Psr\Container\{ContainerExceptionInterface, ContainerInterface};
use Rade\DI\Exceptions\NotFoundServiceException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A container supporting multiple PSR-11 containers.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class FallbackContainer extends Container
{
    /** @var ContainerInterface[] A list of fallback PSR-11 containers */
    protected array $fallbacks = [];

    public function __construct()
    {
        parent::__construct();
        $this->types += [self::class => ['container']]; // Autowire this container
    }

    /**
     * Register a PSR-11 fallback container.
     */
    public function fallback(ContainerInterface $fallback): self
    {
        $this->fallbacks[\get_class($fallback)] = $fallback;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        try {
            $service = $this->fallbacks[$id] ?? parent::get($id, $invalidBehavior);
        } catch (NotFoundServiceException $e) {
            foreach ($this->fallbacks as $container) {
                try {
                    return self::$services[$id] = $container->get($id);
                } catch (ContainerExceptionInterface $e) {
                    // Fallback services not allowed to throw a PSR-11 exception.
                }
            }
        }

        return $service ?? $this->createNotFound($id, true);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        foreach ($this->fallbacks as $container) {
            if ($container->has($id)) {
                return true;
            }
        }

        return isset($this->fallbacks[$id]) || parent::has($id);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        parent::reset();

        // A container such as Symfony DI support reset ...
        foreach ($this->fallbacks as $fallback) {
            if ($fallback instanceof ResetInterface) {
                $fallback->reset();
            }
        }

        $this->fallbacks = [];
    }
}
