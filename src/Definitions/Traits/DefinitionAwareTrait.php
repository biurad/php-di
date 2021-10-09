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

namespace Rade\DI\Definitions\Traits;

use Nette\Utils\Arrays;
use Rade\DI\AbstractContainer;

/**
 * DefinitionAware trait.
 *
 * This trait is currently under re-thinking process, and can potentially changed to
 * be (deprecated) for a more stable approach in attaching container to definition class.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait DefinitionAwareTrait
{
    protected AbstractContainer $container;

    protected ?string $innerId = null;

    /** @var array<int,string> */
    private array $tags;

    public function bindWith(string $id, AbstractContainer $container): void
    {
        $this->innerId = $id;
        $this->container = $container;
    }

    /**
     * Adds a tag for this definition.
     *
     * @param mixed $value
     *
     * @return $this
     */
    public function tag(string $name, $value = true)
    {
        if (null !== $this->innerId) {
            $this->tags[] = $name;
            $this->container->tag($this->innerId, $name, $value);
        } else {
            $this->tags[$name] = $value;
        }

        return $this;
    }

    /**
     * Sets tags for this definition.
     *
     * @return $this
     */
    public function tags(array $tags)
    {
        $tags = Arrays::normalize($tags, true);

        foreach ($tags as $tag => $value) {
            $this->tag($tag, $value);
        }

        return $this;
    }

    /**
     * If definition has tag, a value will be returned else null.
     *
     * @return mixed
     */
    public function tagged(string $name)
    {
        if (null !== $this->innerId) {
            return $this->container->tagged($name, $this->innerId);
        }

        return $this->tags[$name] ?? null;
    }

    /**
     * Whether tags exists in definition.
     */
    public function hasTags(): bool
    {
        return !empty($this->tags);
    }

    /**
     * Returns all tags.
     *
     * @return array<string,mixed>
     */
    public function getTags(): array
    {
        $tagged = [];

        foreach ($this->tags as $tag) {
            $tagged[$tag] = $this->tagged($tag);
        }

        return $tagged;
    }
}
