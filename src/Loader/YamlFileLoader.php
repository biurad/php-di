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

use Rade\DI\Builder\Reference;
use Rade\DI\Builder\Statement;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Definition;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser as YamlParser;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * YamlFileLoader loads YAML files service definitions.
 *
 * @experimental in 1.0
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class YamlFileLoader extends FileLoader
{
    private const DEFAULTS_KEYWORDS = [
        'private' => 'private',
        'tags' => 'tags',
        'autowire' => 'autowire',
        'bind' => 'bind',
        'calls' => 'bind',
    ];

    private const SERVICE_KEYWORDS = [
        'alias' => 'alias',
        'entity' => 'entity',
        'class' => 'entity', // backward compatibility for symfony devs, will be dropped in 2.0
        'arguments' => 'arguments',
        'lazy' => 'lazy',
        'private' => 'private',
        'deprecated' => 'deprecated',
        'factory' => 'factory',
        'tags' => 'tags',
        'decorates' => 'decorates',
        'autowire' => 'autowire',
        'bind' => 'bind',
        'calls' => 'bind',
    ];

    private const PROTOTYPE_KEYWORDS = [
        'resource' => 'resource',
        'namespace' => 'namespace',
        'exclude' => 'exclude',
        'lazy' => 'lazy',
        'private' => 'private',
        'deprecated' => 'deprecated',
        'factory' => 'factory',
        'tags' => 'tags',
        'autowire' => 'autowire',
        'arguments' => 'arguments',
        'bind' => 'bind',
        'calls' => 'bind',
    ];

    private ?YamlParser $yamlParser = null;

    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null): void
    {
        $path = $this->locator->locate($resource);
        $content = $this->loadFile($path);

        if ($this->container instanceof ContainerBuilder) {
            $this->container->addResource(new FileExistenceResource($path));
            $this->container->addResource(new FileResource($path));
        }

        if (!empty($content)) {
            $this->loadContent($content, $path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, string $type = null)
    {
        if (!\is_string($resource)) {
            return false;
        }

        if (null === $type && \in_array(\pathinfo($resource, \PATHINFO_EXTENSION), ['yaml', 'yml'], true)) {
            return true;
        }

        return \in_array($type, ['yaml', 'yml'], true);
    }

    /**
     * Loads a YAML file.
     *
     * @throws \InvalidArgumentException when the given file is not a local file or when it does not exist
     *
     * @return array<int|string,mixed> The file content
     */
    protected function loadFile(string $file): array
    {
        if (!\class_exists(\Symfony\Component\Yaml\Parser::class)) {
            throw new \RuntimeException('Unable to load YAML config files as the Symfony Yaml Component is not installed.');
        }

        if (!\is_file($file)) {
            throw new \InvalidArgumentException(\sprintf(\stream_is_local($file) ? 'The file "%s" does not exist.' : 'This is not a local file "%s".', $file));
        }

        if (null === $this->yamlParser) {
            $this->yamlParser = new YamlParser();
        }

        try {
            $configuration = $this->yamlParser->parseFile($file, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The file "%s" does not contain valid YAML: ', $file) . $e->getMessage(), 0, $e);
        }

        if (null === $configuration) {
            return [];
        }

        if (!\is_array($configuration)) {
            throw new \InvalidArgumentException(\sprintf('The service file "%s" is not valid. It should contain an array. Check your YAML syntax.', $file));
        }

        return $configuration;
    }

    /**
     * @param array<string,mixed> $content
     */
    private function parseImports(array $content, string $file): void
    {
        if (!isset($content['imports'])) {
            return;
        }

        if (!\is_array($content['imports'])) {
            throw new \InvalidArgumentException(\sprintf('The "imports" key should contain an array in "%s". Check your YAML syntax.', $file));
        }

        $defaultDirectory = \dirname($file);

        foreach ($content['imports'] as $import) {
            if (!\is_array($import)) {
                $import = ['resource' => $import];
            }

            if (!isset($import['resource'])) {
                throw new \InvalidArgumentException(\sprintf('An import should provide a resource in "%s". Check your YAML syntax.', $file));
            }

            if ('not_found' === $ignoreErrors = $import['ignore_errors'] ?? false) {
                $notFound = $ignoreErrors = false; // Ignore error on missing file resource.
            }

            $this->setCurrentDir($defaultDirectory);

            try {
                $this->import($import['resource'], $import['type'] ?? null, $ignoreErrors, $file);
            } catch (LoaderLoadException $e) {
                if (!isset($notFound)) {
                    throw $e;
                }
            }
        }

        unset($content['imports']);
    }

    /**
     * @param array<string,mixed> $content
     */
    private function loadContent(array $content, string $path): void
    {
        // imports
        $this->parseImports($content, $path);

        // parameters
        if (isset($content['parameters'])) {
            if (!\is_array($content['parameters'])) {
                throw new \InvalidArgumentException(\sprintf('The "parameters" key should contain an array in "%s". Check your YAML syntax.', $path));
            }

            foreach ($content['parameters'] as $key => $value) {
                $this->container->parameters[$key] = $this->resolveServices($value, $path, true);
            }

            unset($content['parameters']);
        }

        // service providers
        $this->loadServiceProviders($content, $path);

        $this->setCurrentDir(\dirname($path));

        // load definitions
        $this->parseDefinitions($content, $path);
    }

    /**
     * Loads Service Providers.
     *
     * @param array<string,mixed> $content
     */
    private function loadServiceProviders(array $content, string $path): void
    {
        foreach ($content['service_providers'] ?? [] as $k => $provider) {
            if (\is_string($k)) {
                throw new \InvalidArgumentException(\sprintf('Invalid service provider key %s, only list sequence is supported "service_providers: ..." in "%s".', $k, $path));
            }

            if ($provider instanceof TaggedValue && 'provider' === $provider->getTag()) {
                $value = $provider->getValue();

                $provider = $value['id'] ?? null;
                $args = $this->resolveServices($value['args'] ?? [], $path);
                $config = $this->resolveServices($value['config'] ?? [], $path);
            } elseif (\is_array($provider)) {
                $provider = \key($value = $provider);
                $config = $this->resolveServices($value[\method_exists($provider, 'getId') ? $provider::getId() : $provider] ?? [], $path);
            }

            if (!\is_string($provider)) {
                continue;
            }

            if ($this->container instanceof Container) {
                $extension = $this->container->resolveClass($provider, $args ?? []);
            } else {
                $extension = (new \ReflectionClass($provider))->newInstanceArgs($args ?? []);
            }

            $this->container->register($extension, $config ?? $content[$provider] ?? []);
        }

        unset($content['service_providers']);
    }

    /**
     * Resolves services.
     *
     * @param TaggedValue|array|string|null $value
     *
     * @return array|string|Reference|Statement|object|null
     */
    private function resolveServices($value, string $file, bool $isParameter = false)
    {
        if ($value instanceof TaggedValue) {
            if ($isParameter) {
                throw new \InvalidArgumentException(\sprintf('Using tag "!%s" in a parameter is not allowed in "%s".', $value->getTag(), $file));
            }

            $argument = $value->getValue();

            if ('reference' === $value->getTag()) {
                if (!\is_string($argument)) {
                    throw new \InvalidArgumentException(\sprintf('"!reference" tag only accepts string value in "%s".', $file));
                }

                if (!$this->container->has($argument)) {
                    throw new \InvalidArgumentException(\sprintf('Creating an alias using the tag "!reference" is not allowed in "%s".', $file));
                }

                return $this->container instanceof Container ? $this->container->get($argument) : new Reference($argument);
            }

            if ('tagged' === $value->getTag()) {
                if (\is_string($argument) && '' !== $argument) {
                    return $this->container->tagged($argument);
                }

                if (\is_array($argument) && (isset($argument['tag']) && '' !== $argument['tag'])) {
                    return $this->container->tagged($argument['tag'], $argument['resolve'] ?? true);
                }

                throw new \InvalidArgumentException(\sprintf('"!%s" tags only accept a non empty string or an array with a key "tag" in "%s".', $value->getTag(), $file));
            }

            if ('statement' === $value->getTag()) {
                if (\is_string($argument)) {
                    return $this->container instanceof Container ? $this->container->call($argument) : new Statement($argument);
                }

                if (!\is_array($argument)) {
                    throw new \InvalidArgumentException(\sprintf('"!statement" tag only accepts sequences in "%s".', $file));
                }

                if (\array_keys($argument) !== ['value', 'args']) {
                    throw new \InvalidArgumentException('"!statement" tag only accepts array keys of "value" and "args"');
                }

                $argument = $this->resolveServices($argument, $file, $isParameter);

                if ($this->container instanceof Container) {
                    return $this->container->call($argument['value'], $argument['args']);
                }

                return new Statement($argument['value'], $argument['args']);
            }

            throw new \InvalidArgumentException(\sprintf('Unsupported tag "!%s".', $value->getTag()));
        }

        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveServices($v, $file, $isParameter);
            }
        } elseif (\is_string($value) && '@' === $value[0]) {
            $value = \substr($value, 1);

            // double @@ should be escaped
            if ('@' === $value[0]) {
                return $value;
            }

            // ignore on invalid reference
            if ('?' === $value[0]) {
                $value = \substr($value, 1);

                if (!$this->container->has($value)) {
                    return null;
                }
            }

            return $this->container instanceof Container ? $this->container->get($value) : new Reference($value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        return $this->resolveParameters($value);
    }

    /**
     * @param array<string,mixed> $content
     */
    private function parseDefinitions(array $content, string $file): void
    {
        if (!isset($content['services'])) {
            return;
        }

        if (!\is_array($content['services'])) {
            throw new \InvalidArgumentException(\sprintf('The "services" key should contain an array in "%s". Check your YAML syntax.', $file));
        }

        $defaults = $this->parseDefaults($content, $file);

        foreach ($content['services'] as $id => $service) {
            if (\preg_match('/^_[a-zA-Z0-9_]*$/', $id)) {
                throw new \InvalidArgumentException(\sprintf('Service names that start with an underscore are reserved. Rename the "%s" service.', $id));
            }

            $service = $this->resolveServices($service, $file);

            if ($service instanceof Reference) {
                $this->container->alias($id, (string) $service);

                continue;
            }

            if ($service instanceof Statement) {
                $this->container->autowire($id, $service);

                continue;
            }

            if (\is_object($service)) {
                $this->container->set($id, $service, true);

                continue;
            }

            if (empty($service)) {
                if ([] === $defaults) {
                    continue;
                }

                $service = []; // If $defaults, then a definition creation should be possible.
            }

            if (!\is_array($service)) {
                throw new \InvalidArgumentException(\sprintf('A service definition must be an array, a tagged "!statement" or a string starting with "@", but "%s" found for service "%s" in "%s". Check your YAML syntax.', \get_debug_type($service), $id, $file));
            }

            if ($this->container->has($id) && $this->container instanceof Container) {
                $this->container->extend($id, function (Definition $definition) use ($id, $service, $file, $defaults): Definition {
                    $this->parseDefinition($id, $service, $file, $defaults, $definition);

                    return $definition;
                });

                continue;
            }

            $this->parseDefinition($id, $service, $file, $defaults);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function parseDefaults(array &$content, string $file): array
    {
        if (!\array_key_exists('_defaults', $content['services'])) {
            return [];
        }
        $defaults = $content['services']['_defaults'];
        unset($content['services']['_defaults']);

        if (!\is_array($defaults)) {
            throw new \InvalidArgumentException(\sprintf('Service "_defaults" key must be an array, "%s" given in "%s".', \get_debug_type($defaults), $file));
        }

        foreach ($defaults as $key => $default) {
            if (!isset(self::DEFAULTS_KEYWORDS[$key])) {
                throw new \InvalidArgumentException(\sprintf('The configuration key "%s" cannot be used to define a default value in "%s". Allowed keys are "%s".', $key, $file, \implode('", "', self::DEFAULTS_KEYWORDS)));
            }
        }

        if (isset($defaults['tags'])) {
            if (!\is_array($tags = $defaults['tags'])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "tags" in "_defaults" must be an array in "%s". Check your YAML syntax.', $file));
            }

            $defaults['tags'] = $this->parseDefinitionTags('in "_defaults"', $tags, $file);
        }

        if (null !== $bindings = $defaults['bind'] ?? $default['calls'] ?? null) {
            if (!\is_array($bindings)) {
                throw new \InvalidArgumentException(\sprintf('Parameter "bind" in "_defaults" must be an array in "%s". Check your YAML syntax.', $file));
            }

            unset($default['calls']); // To avoid conflicts, will be dropped in 1.3

            $defaults['bind'] = $this->parseDefinitionBinds('in "_defaults"', $bindings, $file);
        }

        return $defaults;
    }

    /**
     * Parses a definition.
     *
     * @param array<string,mixed> $service
     * @param array<string,mixed> $defaults
     *
     * @throws \InvalidArgumentException
     */
    private function parseDefinition(string $id, array $service, string $file, array $defaults, Definition $definition = null): void
    {
        $this->checkDefinition($id, $service, $file);

        if ($this->container->has($id) && $this->container instanceof ContainerBuilder) {
            $definition = \array_key_exists($id, $this->container->keys()) ? $this->container->extend($id) : null;
        }

        // Non existing entity
        if (!isset($service['entity'])) {
            $service['entity'] = $service['class'] ?? (\class_exists($id) ? $id : null);
        }

        $arguments = $this->resolveServices($service['arguments'] ?? [], $file);

        if ($definition instanceof Definition) {
            $hasDefinition = true;

            $definition->replace($service['entity'], null !== $service['entity'])
                ->args(\array_merge($definition->get('parameters'), $arguments));
        } else {
            $definition = new Definition($service['entity'], $arguments);
        }

        if ($this->container instanceof ContainerBuilder) {
            $definition->should(Definition::PRIVATE, $service['private'] ?? $defaults['private'] ?? false);
        }

        $definition->should(Definition::LAZY, $service['lazy'] ?? false);
        $definition->should(Definition::FACTORY, $service['factory'] ?? false);
        $this->autowired[$id] = $autowired = $service['autowire'] ?? $defaults['autowire'] ?? false;

        if (isset($service['deprecated'])) {
            $deprecation = \is_array($service['deprecated']) ? $service['deprecated'] : ['message' => $service['deprecated']];
            $deprecation = [$deprecation['package'] ?? '', $deprecation['version'] ?? '', $deprecation['message'] ?? null];
        }

        if (!\is_array($bindings = $service['bind'] ?? $service['calls'] ?? [])) {
            throw new \InvalidArgumentException(\sprintf('Parameter "bind" must be an array for service "%s" in "%s". Check your YAML syntax.', $id, $file));
        }
        $bindings = \array_merge($defaults['bind'] ?? [], $bindings);

        if ([] !== $bindings) {
            $this->parseDefinitionBinds($id, $bindings, $file, $definition);
        }

        if (!\is_array($tags = $service['tags'] ?? [])) {
            throw new \InvalidArgumentException(\sprintf('Parameter "tags" must be an array for service "%s" in "%s". Check your YAML syntax.', $id, $file));
        }

        if (isset($defaults['tags'])) {
            $tags = \array_merge($defaults['tags'], $tags);
        }

        if (\array_key_exists('namespace', $service) && !\array_key_exists('resource', $service)) {
            throw new \InvalidArgumentException(\sprintf('A "resource" attribute must be set when the "namespace" attribute is set for service "%s" in "%s". Check your YAML syntax.', $id, $file));
        }

        if (\array_key_exists('resource', $service)) {
            if (!\is_string($service['resource'])) {
                throw new \InvalidArgumentException(\sprintf('A "resource" attribute must be of type string for service "%s" in "%s". Check your YAML syntax.', $id, $file));
            }

            $namespace = $service['namespace'] ?? $id;

            $this->autowired[$namespace] = $autowired;
            $this->deprecations[$namespace] = $deprecation ?? null;

            if ([] !== $tags) {
                $this->tags[$namespace] = $this->parseDefinitionTags($id, $tags, $file);
            }

            $this->registerClasses($definition, $namespace, $service['resource'], $service['exclude'] ?? []);

            return;
        }

        if (!isset($hasDefinition)) {
            $definition = $this->container->set($id, $definition);
        }

        if (false !== $autowired) {
            $definition->autowire(\is_array($autowired) ? $autowired : []);
        }

        if (isset($deprecation)) {
            [$package, $version, $message] = $deprecation;

            $definition->deprecate($package, $version, $message);
        }

        if ([] !== $tags) {
            $this->container->tag($id, $this->parseDefinitionTags($id, $tags, $file));
        }
    }

    /**
     * @param array<int,string[]> $bindings
     *
     * @return array<int,mixed>
     */
    private function parseDefinitionBinds(string $id, array $bindings, string $file, Definition $definition = null): array
    {
        if ('in "_defaults"' !== $id) {
            $id = \sprintf('for service "%s"', $id);
        }

        foreach ($bindings as $k => $call) {
            if (!\is_array($call) && (!\is_string($k) || !$call instanceof TaggedValue)) {
                throw new \InvalidArgumentException(\sprintf('Invalid bind call %s: expected map or array, "%s" given in "%s".', $id, $call instanceof TaggedValue ? '!' . $call->getTag() : \get_debug_type($call), $file));
            }

            if (\is_string($k)) {
                throw new \InvalidArgumentException(\sprintf('Invalid bind call %s, did you forgot a leading dash before "%s: ..." in "%s"?', $id, $k, $file));
            }

            if (empty($call)) {
                throw new \InvalidArgumentException(\sprintf('Invalid call %s: the bind must be defined as the first index of an array or as the only key of a map in "%s".', $id, $file));
            }

            if (1 === \count($call) && \is_string(\key($call))) {
                $method = \key($call);
                $args = $this->resolveServices($call[$method], $file);
            } elseif (empty($call)) {
                throw new \InvalidArgumentException(\sprintf('Invalid call %s: the bind must be defined as the first index of an array or as the only key of a map in "%s".', $id, $file));
            } else {
                $method = $call[0];
                $args = $this->resolveServices($call[1] ?? null, $file);
            }

            if ($definition instanceof Definition) {
                $definition->bind($method, $args);

                continue;
            }

            $bindings[$k] = [$method, $args];
        }

        return $bindings;
    }

    /**
     * @param array<int|string,mixed> $tags
     *
     * @return mixed[]
     */
    private function parseDefinitionTags(string $id, array $tags, string $file): array
    {
        if ('in "_defaults"' !== $id) {
            $id = \sprintf('for service "%s"', $id);
        }

        $serviceTags = [];

        foreach ($tags as $k => $tag) {
            if (\is_string($k)) {
                throw new \InvalidArgumentException(\sprintf('The "tags" entry %s is invalid, did you forgot a leading dash before "%s: ..." in "%s"?', $id, $k, $file));
            }

            if (\is_string($tag)) {
                $serviceTags[] = $tag;

                continue;
            }

            foreach ((array) $tag as $name => $value) {
                if (!\is_string($name) || '' === $name) {
                    throw new \InvalidArgumentException(\sprintf('The tag name %s in "%s" must be a non-empty string. Check your YAML syntax.', $id, $file));
                }

                $serviceTags[$name] = $value;
            }
        }

        return $serviceTags;
    }

    /**
     * Checks the keywords used to define a service.
     *
     * @param array<string,mixed> $definition
     */
    private function checkDefinition(string $id, array $definition, string $file): void
    {
        if (isset($definition['resource']) || isset($definition['namespace'])) {
            $keywords = self::PROTOTYPE_KEYWORDS;
        } else {
            $keywords = self::SERVICE_KEYWORDS;
        }

        foreach ($definition as $key => $value) {
            if (!isset($keywords[$key])) {
                throw new \InvalidArgumentException(\sprintf('The configuration key "%s" is unsupported for definition "%s" in "%s". Allowed configuration keys are "%s".', $key, $id, $file, \implode('", "', $keywords)));
            }
        }
    }
}
