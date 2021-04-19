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

namespace Rade\DI\Tests\Fixtures;

use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Psr\Log\LoggerInterface;

class SomeServiceSubscriber extends SomeService implements ServiceSubscriberInterface
{
    public function __construct(ServiceProviderInterface $provider = null)
    {
        parent::__construct($provider);
    }

    public static function getSubscribedServices(): array
    {
        return [
            'logger' => LoggerInterface::class,
            'loggers' => 'Psr\Log\LoggerInterface[]',
            Service::class => 'Rade\DI\Tests\Fixtures\Service[]',
            'Rade\DI\Tests\Fixtures\Invokable[]',
            '?non_array[]',
            Constructor::class => Constructor::class,
            NullLogger::class,
            'o_logger' => '?Psr\Log\LoggerInterface',
            '?Psr\Log\LoggerInterface',
            '?none',
        ];
    }
}
