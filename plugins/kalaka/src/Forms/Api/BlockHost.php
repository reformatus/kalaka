<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Lets some of Kaláka's gated blocks (the field blocks and the form-only blocks, e.g. the condition
 * blocks) be inserted in an editor other than a form's, contributed through `Contributions::blockHost()`
 * by any module: a mail body (`kalaka_mail`), a portal page, or a site part shown in a participant's
 * context. Data only. It applies to a post type, to a block editor context by name (e.g.
 * `core/edit-site`), or to both; Forms checks it without knowing the module. A form's editor allows the
 * gated blocks without a host; any other editor allows only those a host that applies to it names.
 */
final readonly class BlockHost
{
    /**
     * @param list<string> $blocks block names, e.g. `kalaka/if`
     * @param string|null $postType the post type of the edited post
     * @param string|null $editor the block editor context's name (`WP_Block_Editor_Context::$name`)
     * @throws \InvalidArgumentException when it names neither a post type nor an editor
     */
    public function __construct(
        public array $blocks,
        public ?string $postType = null,
        public ?string $editor = null,
    ) {
        if ($postType === null && $editor === null) {
            throw new \InvalidArgumentException('A block host names a post type, an editor or both.');
        }
    }

    /** Whether it applies to an editor of this post type (null: no post) and this context name. */
    public function appliesTo(?string $postType, ?string $editor): bool
    {
        return ($this->postType === null || $this->postType === $postType)
            && ($this->editor === null || $this->editor === $editor);
    }
}
