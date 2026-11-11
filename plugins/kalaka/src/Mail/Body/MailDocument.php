<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

/** A published mail body as it is now; its title is the subject. */
final readonly class MailDocument
{
    /** @param int $revision the latest revision that is not an autosave: the document's version */
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public int $revision,
    ) {
    }
}
