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

use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract, ParserFactory};
use PhpParser\Node\Scalar\String_;
use Rade\DI\Resolvers\Resolver;

/**
 * PHP literal value.
 *
 * Example:
 *
 * ```php
 * $literal = new PhpLiteral('$hello = ['??' => '??'];', ['Hello', '344']);
 * // Expected output when resolved is: $hello => ['Hello' => 344];
 * ```
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class PhpLiteral
{
    private string $value;

    private array $args;

    /**
     * `??` is a reserved string in code, as it used to resolve missing values.
     *
     * @param string $value Should be a php code excluding `<?php`
     * @param array<int,mixed> $args
     */
    public function __construct(string $value, array $args = [])
    {
        $this->args = $args;
        $this->value = $value;
    }

    public function resolve(Resolver $resolver)
    {
        return (function () use ($resolver) {
            $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
            $astCode = $parser->parse("<?php\n" . $this->value);

            if ([] !== $this->args) {
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class ($resolver, $this->args) extends NodeVisitorAbstract {
                    private Resolver $resolver;

                    private int $offset = -1;

                    private array $args;

                    public function __construct(Resolver $resolver, array $args)
                    {
                        $this->args = $args;
                        $this->resolver = $resolver;
                    }

                    public function enterNode(Node $node)
                    {
                        if ($node instanceof String_ && '??' === $node->value) {
                            $value = $this->args[++$this->offset];

                            if (\is_array($value)) {
                                return $this->resolver->getBuilder()->val($this->resolver->resolveArguments($value));
                            }

                            return $this->resolver->resolve($value);
                        }
                    }
                });

                $astCode = $traverser->traverse($astCode);
            }

            return $astCode;
        })();
    }
}
