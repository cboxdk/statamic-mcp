<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;
use Tiptap\Core\Mark;
use Tiptap\Core\Node;
use Tiptap\Core\Schema;

/** The node vocabulary belongs to Statamic and its registered Bard extensions. */
final class BardNodes
{
    /** @var list<string> */
    public readonly array $nodes;

    /** @var list<string> */
    public readonly array $marks;

    public function __construct(Bard $bard)
    {
        $nodes = $marks = [];
        foreach ((new Schema((new Augmentor($bard))->extensions()))->allExtensions as $extension) {
            if ($extension instanceof Node && is_string($extension::$name) && $extension::$name !== 'doc') {
                $nodes[] = $extension::$name;
            } elseif ($extension instanceof Mark && is_string($extension::$name)) {
                $marks[] = $extension::$name;
            }
        }
        $this->nodes = $nodes;
        $this->marks = $marks;
    }

    /** @param array<array-key, mixed> $nodes */
    public function validate(array $nodes, string $path, bool $inline = false, bool $root = true): void
    {
        if (! array_is_list($nodes)) {
            throw new FieldFormatException("Field [{$path}] expects a list of Bard nodes.");
        }
        foreach ($nodes as $index => $node) {
            $at = "{$path}.{$index}";
            if (! is_array($node) || ! is_string($type = $node['type'] ?? null) || ! in_array($type, $this->nodes, true)) {
                throw new FieldFormatException("Field [{$at}] has an unknown Bard node type.");
            }
            if ($inline && ! in_array($type, ['text', 'hardBreak'], true)) {
                throw new FieldFormatException("Field [{$at}] expects inline Bard nodes (text or hardBreak).");
            }
            if ($type === 'set' && ! $root) {
                throw new FieldFormatException("Field [{$at}] must place Bard sets at the root, not inside another node.");
            }
            if ($type === 'text' && ! is_string($node['text'] ?? null)) {
                throw new FieldFormatException("Field [{$at}.text] expects a string.");
            }
            if (array_key_exists('attrs', $node) && ! is_array($node['attrs'])) {
                throw new FieldFormatException("Field [{$at}.attrs] expects an object.");
            }
            if (array_key_exists('marks', $node)) {
                if (! is_array($node['marks']) || ! array_is_list($node['marks'])) {
                    throw new FieldFormatException("Field [{$at}.marks] expects a list.");
                }
                foreach ($node['marks'] as $mark) {
                    if (! is_array($mark) || ! in_array($mark['type'] ?? null, $this->marks, true)
                        || (array_key_exists('attrs', $mark) && ! is_array($mark['attrs']))) {
                        throw new FieldFormatException("Field [{$at}.marks] has an unknown or malformed Bard mark.");
                    }
                }
            }
            if (array_key_exists('content', $node)) {
                if (! is_array($node['content'])) {
                    throw new FieldFormatException("Field [{$at}.content] expects a list of Bard nodes.");
                }
                $this->validate($node['content'], "{$at}.content", $inline, false);
            }
        }
    }
}
