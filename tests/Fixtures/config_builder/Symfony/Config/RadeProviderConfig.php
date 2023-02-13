<?php

namespace Symfony\Config;

use Symfony\Component\Config\Loader\ParamConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * This class is automatically generated to help creating config.
 *
 * @experimental in 5.3
 */
class RadeProviderConfig implements \Symfony\Component\Config\Builder\ConfigBuilderInterface
{
    private $hello;

    /**
     * @default null
     * @param ParamConfigurator|mixed $value
     * @return $this
     */
    public function hello($value): self
    {
        $this->hello = $value;

        return $this;
    }

    public function getExtensionAlias(): string
    {
        return 'rade_provider';
    }


    public function __construct(array $value = [])
    {

        if (isset($value['hello'])) {
            $this->hello = $value['hello'];
            unset($value['hello']);
        }

        if ([] !== $value) {
            throw new InvalidConfigurationException(sprintf('The following keys are not supported by "%s": ', __CLASS__) . implode(', ', array_keys($value)));
        }
    }


    public function toArray(): array
    {
        $output = [];
        if (null !== $this->hello) {
            $output['hello'] = $this->hello;
        }

        return $output;
    }
}
