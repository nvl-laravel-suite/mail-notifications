<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/** Matches provider-verified senders or exact email domains using safely quoted managed identifiers. */
final readonly class SenderDomainMatchExpression implements Expression
{
    public function __construct(private bool $sqlite) {}

    /**
     * Compile the sender/domain comparison using the active database grammar and case-folding policy.
     */
    public function getValue(Grammar $grammar): string
    {
        $sender = ManagedConfiguration::table('sender_identities');
        $domain = ManagedConfiguration::table('sending_domains');
        $email = $grammar->wrap($sender.'.email');
        $source = $grammar->wrap($sender.'.verification_source');
        $name = $grammar->wrap($domain.'.domain');
        $length = $this->sqlite ? 'LENGTH' : 'CHAR_LENGTH';

        return "({$source} = ? OR (LOWER(SUBSTR({$email}, {$length}({$email}) - {$length}({$name}) + 1)) = LOWER({$name}) AND SUBSTR({$email}, {$length}({$email}) - {$length}({$name}), 1) = ?))";
    }
}
