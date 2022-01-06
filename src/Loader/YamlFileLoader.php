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

use Rade\DI\Definitions\{ChildDefinition, DefinitionInterface, Reference, Statement};
use Rade\DI\{ContainerBuilder, Definition, DefinitionBuilder};
use Rade\DI\Builder\PhpLiteral;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\Resource\{FileExistenceResource, FileResource};
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
        'public' => 'public',
        'tags' => 'tags',
        'autowire' => 'autowire',
        'bind' => 'bind',
        'calls' => 'bind',
        'configure' => 'configure',
    ];

    private const INSTANCEOF_KEYWORDS = [
        'shared' => 'shared',
        'lazy' => 'lazy',
        'public' => 'public',
        'bind' => 'bind',
        'calls' => 'bind',
        'configure' => 'configure',
        'tags' => 'tags',
        'autowire' => 'autowire',
    ];

    private const SERVICE_KEYWORDS = [
        'alias' => 'alias',
        'parent' => 'parent',
        'entity' => 'entity',
        'class' => 'entity', // backward compatibility for symfony devs, will be dropped in 2.0
        'abstract' => 'abstract',
        'arguments' => 'arguments',
        'lazy' => 'lazy',
        'public' => 'public',
        'deprecated' => 'deprecated',
        'shared' => 'shared',
        'tags' => 'tags',
        'decorates' => 'decorates',
        'autowire' => 'autowire',
        'bind' => 'bind',
        'calls' => 'bind',
        'configure' => 'configure',
    ];

    private const PROTOTYPE_KEYWORDS = [
        'resource' => 'resource',
        'namespace' => 'namespace',
        'exclude' => 'exclude',
        'lazy' => 'lazy',
        'public' => 'public',
        'parent' => 'parent',
        'deprecated' => 'deprecated',
        'shared' => 'shared',
        'tags' => 'tags',
        'autowire' => 'autowire',
        'arguments' => 'arguments',
        'bind' => 'bind',
        'calls' => 'bind',
        'configure' => 'configure',
        'instanceOf' => 'instanceOf',
    ];

    private ?YamlParser $yamlParser = null;

    private bool $isLoadingInstanceof = false;

    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null): void
    {
        $path = $this->locator->locate($resource);
        $content = $this->loadFile($path);
        $container = $this->builder->getContainer();

        if ($container instanceof ContainerBuilder) {
            $container->addResource(new FileExistenceResource($path));
            $container->addResource(new FileResource($path));
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
     * @return array<string,mixed> The file content
     */
    protected function loadFile(string $file): array
    {
        if (!\class_exists(\Symfony\Component\Yaml\Parser::class)) {
            throw new \RuntimeException('Unable to load YAML config files as the Symfony Yaml Component is not installed.');
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

        $this->builder->directory($defaultDirectory = \dirname($file));

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
                $this->builder->parameter($key, $this->resolveServices($value, $path, true));
            }

            unset($content['parameters']);
        }

        $this->setCurrentDir($dir = \dirname($path));
        $this->builder->directory($dir);

        // load definitions
        $this->parseDefinitions($content, $path);
    }

    /**
     * Resolves services.
     *
     * @param TaggedValue|array|string|null $value
     *
     * @return array<string,mixed>|string|Reference|Statement|object|null
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

                if (!$this->builder->getContainer()->has($argument)) {
                    throw new \InvalidArgumentException(\sprintf('Creating an alias using the tag "!reference" is not allowed in "%s".', $file));
                }

                return new Reference($argument);
            }

            if ('tagged' === $value->getTag()) {
                if (\is_string($argument) && '' !== $argument) {
                    return $this->builder->getContainer()->tagged($argument);
                }

                if (\is_array($argument) && (isset($argument['tag']) && '' !== $argument['tag'])) {
                    $taggedIds = $this->builder->getContainer()->tagged($argument['tag'], $argument['id'] ?? null);

                    if (true === $argument['resolve'] ?? false) {
                        $tagged = [];

                        foreach ($taggedIds as $serviceId => $value) {
                            $tagged[] = [new Reference($serviceId), $value];
                        }

                        return $tagged;
                    }

                    return $taggedIds;
                }

                throw new \InvalidArgumentException(\sprintf('"!%s" tags only accept a non empty string or an array with a key "tag" in "%s".', $value->getTag(), $file));
            }

            if ('statement' === $value->getTag()) {
                if (\is_string($argument)) {
                    return new Statement($argument);
                }

                if (!\is_array($argument)) {
                    throw new \InvalidArgumentException(\sprintf('"!statement" tag only accepts sequences in "%s".', $file));
                }

                if (!\array_key_exists('value', $argument)) {
                    throw new \InvalidArgumentException('"!statement" tag only accepts array keys of "value" and "args"');
                }

                return new Statement($argument['value'], $this->resolveServices($argument['args'] ?? [], $file, $isParameter), $argument['closure'] ?? false);
            }

            if ('php_literal' === $value->getTag()) {
                if (\is_string($argument)) {
                    return new PhpLiteral($argument);
                }

                if (!\is_array($argument)) {
                    throw new \InvalidArgumentException(\sprintf('"!php_literal" tag only accepts list sequences in "%s".', $file));
                }

                if (2 !== \count($argument, \COUNT_RECURSIVE)) {
                    throw new \InvalidArgumentException(\sprintf('"!%s" tag only accepts a one count map array in "%s", key as the string, and value as array.', $value->getTag(), $file));
                }

                return new PhpLiteral(\key($argument), $this->resolveServices(\current($argument), $file, $isParameter));
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

                if (!($this->builder->getContainer()->has($value) || $this->builder->getContainer()->typed($value))) {
                    return null;
                }
            }

            return new Reference($value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        return $this->builder->getContainer()->parameter($value);
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

        $hasInstance = $this->parseDefaults($content, $file, '_instanceof', [], '');
        $hasDefaults = $this->parseDefaults($content, $file);

        foreach ($content['services'] as $id => $service) {
            if (\preg_match('/^_[a-zA-Z0-9_]*$/', $id)) {
                throw new \InvalidArgumentException(\sprintf('Service names that start with an underscore are reserved. Rename the "%s" service.', $id));
            }

            $service = $this->resolveServices($service, $file);

            if ($service instanceof Reference) {
                $this->builder->alias($id, (string) $service);

                continue;
            }

            if ($service instanceof Statement) {
                $service = ['entity' => new Statement($service->getValue(), $this->resolveServices($service->getArguments(), $file), $service->isClosureWrappable())];
            }

            if (empty($service)) {
                if (!$hasDefaults || !$hasInstance) {
                    continue;
                }

                $service = []; // If $defaults, then a definition creation should be possible.
            }

            if (!\is_array($service)) {
                throw new \InvalidArgumentException(\sprintf('A service definition must be an array, a tagged "!statement" or a string starting with "@", but "%s" found for service "%s" in "%s". Check your YAML syntax.', \get_debug_type($service), $id, $file));
            }

            $this->parseDefinition($id, $service, $file);
        }
    }

    /**
     * @param array<string,mixed> $defaults
     *
     * @throws \InvalidArgumentException
     */
    private function parseDefaults(array &$content, string $file, $name = '_defaults', $defaults = [], string $instanceof = null): bool
    {
        if (empty($defaults) && !\array_key_exists($name, $content['services'])) {
            return false;
        }

        if (empty($defaults)) {
            $defaults = $content['services'][$name];
            unset($content['services'][$name]);
        }

        if (!\is_array($defaults)) {
            throw new \InvalidArgumentException(\sprintf('Service "%s" key must be an array, "%s" given in "%s".', $name, \get_debug_type($defaults), $file));
        }

        if (!empty($instanceof)) {
            $this->isLoadingInstanceof = true;
            $this->checkDefinition($instanceof, $defaults, $file);

            $this->builder->instanceOf($instanceof);
        } else {
            foreach ($defaults as $key => $default) {
                if (!isset(self::DEFAULTS_KEYWORDS[$key])) {
                    if (null !== $instanceof) {
                        $this->isLoadingInstanceof = true;
                        $this->checkDefinition($key, $default, $file);

                        if ('' === $instanceof) {
                            $this->parseDefaults($content, $file, $name, $default, $key);
                        }

                        continue;
                    }

                    throw new \InvalidArgumentException(\sprintf('The configuration key "%s" cannot be used to define a default value in "%s". Allowed keys are "%s".', $key, $file, \implode('", "', self::DEFAULTS_KEYWORDS)));
                }
            }

            $this->builder->defaults();
        }

        if (isset($defaults['public'])) {
            $this->builder->public($defaults['public']);
        }

        if (isset($defaults['lazy'])) {
            $this->builder->lazy($defaults['lazy']);
        }

        if (isset($defaults['shared'])) {
            $this->builder->shared($defaults['shared']);
        }

        if (\array_key_exists('autowire', $defaults)) {
            if (!\is_array($autowired = $defaults['autowire'] ?? [])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "autowire" in "%s" must be an array in "%s". Check your YAML syntax.', $name, $file));
            }

            $this->builder->autowire($autowired);
        }

        if (isset($defaults['tags'])) {
            if (!\is_array($tags = $defaults['tags'])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "tags" in "%s" must be an array in "%s". Check your YAML syntax.', $name, $file));
            }

            $this->builder->tags($this->parseDefinitionTags("in \"{$name}\"", $tags, $file));
        }

        if (null !== $bindings = $defaults['bind'] ?? $default['calls'] ?? null) {
            if (!\is_array($bindings)) {
                throw new \InvalidArgumentException(\sprintf('Parameter "bind" in "%s" must be an array in "%s". Check your YAML syntax.', $name, $file));
            }

            $this->parseDefinitionBinds("in \"{$name}\"", $bindings, $file, $this->builder);
        }

        if (isset($defaults['configure'])) {
            if (!\is_array($configures = $defaults['configure'])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "configure" in "%s" must be an array in "%s". Check your YAML syntax.', $name, $file));
            }

            $this->parseDefinitionBinds("in \"{$name}\"", $configures, $file, $this->builder, true);
        }

        return true;
    }

    /**
     * Parses a definition.
     *
     * @param array<string,mixed> $service
     *
     * @throws \InvalidArgumentException
     */
    private function parseDefinition(string $id, array $service, string $file): void
    {
        $this->checkDefinition($id, $service, $file);

        if (\array_key_exists('namespace', $service)) {
            if (!\is_string($service['resource'])) {
                throw new \InvalidArgumentException(\sprintf('A "resource" attribute must be of type string for service "%s" in "%s". Check your YAML syntax.', $id, $file));
            }

            /** @var Definition $definition */
            $definition = $this->builder->namespaced($service['namespace'] ?? $id, $service['resource'] ?? null, $service['exclude'] ?? []);
        } elseif (isset($service['parent'])) {
            if ('' !== $service['parent'] && '@' === $service['parent'][0]) {
                throw new \InvalidArgumentException(\sprintf('The value of the "parent" option for the "%s" service must be the id of the service without the "@" prefix (replace "%s" with "%s").', $id, $service['parent'], \substr($service['parent'], 1)));
            }

            $definition = $this->builder->set($id, new ChildDefinition($service['parent']));

            if (!$definition instanceof DefinitionInterface) {
                return;
            }

            if (null !== $entity = $service['entity'] ?? $service['class'] ?? null) {
                $definition->replace($entity, null !== $entity);
            }
        } else {
            $entity = $service['entity'] ?? $service['class'] ?? $id;

            if ('@' === $id[0]) {
                $definition = $this->builder->decorate(\substr($id, 1), new Definition(\ltrim($entity, '@')));
            } elseif ($this->builder->getContainer()->has($id)) {
                $definition = $this->builder->extend($id)->replace($entity, $id !== $entity);
            } else {
                $definition = $this->builder->set($id, \is_object($entity) ? $entity : new Definition($entity));
            }

            if (!$definition instanceof DefinitionInterface) {
                return;
            }
        }

        if (isset($service['arguments'])) {
            $definition->args($this->resolveServices($service['arguments'], $file));
        }

        if (isset($service['public'])) {
            $this->builder->public($service['public']);
        }

        if (isset($service['lazy'])) {
            $this->builder->lazy($service['lazy']);
        }

        if (isset($service['shared'])) {
            $this->builder->shared($service['shared']);
        }

        if (isset($service['abstract'])) {
            $this->builder->abstract($service['abstract']);
        }

        if (\array_key_exists('autowire', $service)) {
            $definition->autowire($service['autowire'] ?? []);
        }

        if (isset($service['deprecated'])) {
            $deprecation = \is_array($service['deprecated']) ? $service['deprecated'] : ['message' => $service['deprecated']];
            $deprecatedVersion = isset($deprecation['version']) ? (float) $deprecation['version'] : null;

            $definition->deprecate($deprecation['package'] ?? '', $deprecatedVersion, $deprecation['message'] ?? null);
        }

        if (!empty($bindings = $service['bind'] ?? $service['calls'] ?? [])) {
            if (!\is_array($bindings)) {
                throw new \InvalidArgumentException(\sprintf('Parameter "bind" must be an array for service "%s" in "%s". Check your YAML syntax.', $id, $file));
            }

            $this->parseDefinitionBinds($id, $bindings, $file, $definition);
        }

        if (isset($service['configure'])) {
            if (!\is_array($configures = $service['configure'])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "configure" in "%s" must be an array in "%s". Check your YAML syntax.', $id, $file));
            }

            $this->parseDefinitionBinds($id, $configures, $file, $this->builder, true);
        }

        if (isset($service['tags'])) {
            if (!\is_array($tags = $service['tags'])) {
                throw new \InvalidArgumentException(\sprintf('Parameter "tags" must be an array for service "%s" in "%s". Check your YAML syntax.', $id, $file));
            }

            $definition->tags($this->parseDefinitionTags($id, $tags, $file));
        }
    }

    /**
     * @param array<int,string[]|TaggedValue[]> $bindings
     * @param Definition|DefinitionBuilder      $definition
     *
     * @return array<int,mixed>
     */
    private function parseDefinitionBinds(string $id, array $bindings, string $file, $definition, bool $configure = false): void
    {
        if ('in "_defaults"' !== $id) {
            $id = \sprintf('for service "%s"', $id);
        }

        foreach ($bindings as $k => $call) {
            if (\is_string($k)) {
                throw new \InvalidArgumentException(\sprintf('Invalid bind call %s, did you forgot a leading dash before "%s: ..." in "%s"?', $id, $k, $file));
            }

            if (\is_string($call) && $configure) {
                $definition->call($call);

                continue;
            }

            if (!($call instanceof TaggedValue || \is_array($call))) {
                throw new \InvalidArgumentException(\sprintf('Invalid bind call %s: expected map or array, "%s" given in "%s".', $id, $call instanceof TaggedValue ? '!' . $call->getTag() : \get_debug_type($call), $file));
            }

            if (1 === \count($call) && \is_string(\key($call))) {
                $args = $call[$method = \key($call)];

                if ($configure && !\is_bool($args)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid call %s: the configuration bind value must be a bool in "%s".', $id, $file));
                }

                $args = $configure ? $args : $this->resolveServices($args, $file);
            } elseif (empty($call)) {
                throw new \InvalidArgumentException(\sprintf('Invalid call %s: the bind must be defined as the first index of an array or as the only key of a map in "%s".', $id, $file));
            } elseif ($configure) {
                if (isset($call[1]) && !\is_bool($call[1])) {
                    throw new \InvalidArgumentException(\sprintf('Invalid call %s: the configuration bind value must be a bool in "%s".', $id, $file));
                }

                $method = $this->resolveServices($call[0], $file);
                $args = $call[1] ?? true;
            } else {
                if (!\is_string($call[0])) {
                    throw new \InvalidArgumentException(\sprintf('Invalid bind call %s: expected string in first index of array, "%s" given in "%s".', $id, \get_debug_type($call), $file));
                }

                $method = $call[0];
                $args = isset($call[1]) ? $this->resolveServices($call[1], $file) : null;
            }

            $definition->{$configure ? 'call' : 'bind'}($method, $args);
        }
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
            if (!(\is_string($tag) || \is_array($tag))) {
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
        if ($this->isLoadingInstanceof) {
            $keywords = self::INSTANCEOF_KEYWORDS;
            $this->isLoadingInstanceof = false;
        } elseif (isset($definition['resource']) || isset($definition['namespace'])) {
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
