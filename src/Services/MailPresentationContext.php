<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

/** Limits presentation data to an explicitly requested render and its nested components. */
final class MailPresentationContext
{
    private int $depth = 0;

    /** Determine whether an explicit package render is in progress. */
    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    public function render(callable $render): mixed
    {
        $this->depth++;
        try {
            return $render();
        } finally {
            $this->depth--;
        }
    }
}
