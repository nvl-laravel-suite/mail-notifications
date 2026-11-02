<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Integrations;

use JsonSerializable;

/** Retains internal provider outcome, pagination and retry metadata between integration collaborators.
 * @internal
 */
final readonly class ProviderApiResult implements JsonSerializable
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        public string $status,
        public array $data = [],
        public bool $complete = false,
        public ?string $message = null,
        public ?string $nextCursor = null,
        public ?int $total = null,
        public ?int $retryAfter = null,
    ) {}

    /**
     * Expose the internal outcome, page continuation and retry metadata without adding provider credentials.
     *
     * @return array{status: string, data: array<array-key, mixed>, complete: bool, message: ?string, next_cursor?: string, total?: int, retry_after?: int}
     */
    public function jsonSerialize(): array
    {
        $result = ['status' => $this->status, 'data' => $this->data, 'complete' => $this->complete, 'message' => $this->message];
        if ($this->nextCursor !== null) {
            $result['next_cursor'] = $this->nextCursor;
        }
        if ($this->total !== null) {
            $result['total'] = $this->total;
        }
        if ($this->retryAfter !== null) {
            $result['retry_after'] = $this->retryAfter;
        }

        return $result;
    }
}
