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

namespace Rade\DI\Exceptions;

use Psr\Container\ContainerExceptionInterface;

class CircularReferenceException extends \InvalidArgumentException implements ContainerExceptionInterface
{
    private string $serviceId;

    private array $path;

    public function __construct(string $serviceId, array $path, \Throwable $previous = null)
    {
        parent::__construct(\sprintf('Circular reference detected for service "%s", path: "%s".', $serviceId, \implode(' -> ', $path)), 0, $previous);

        $this->serviceId = $serviceId;
        $this->path = $path;
    }

    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    public function getPath(): array
    {
        return $this->path;
    }
}
