<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;
use Symfony\Component\Mime\Address;

/**
 * Represents one normalized outbound email recipient.
 */
final readonly class Recipient
{
    public string $email;

    public ?string $name;

    /**
     * Create a normalized recipient.
     */
    public function __construct(
        string $email,
        ?string $name = null,
    ) {
        $normalizedName = $name !== null && trim($name) !== ''
            ? trim($name)
            : '';

        try {
            $address = new Address($email, $normalizedName);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('A tracked recipient must contain a valid email address.');
        }

        $this->email = $address->getAddress();
        $this->name = $address->getName() !== ''
            ? $address->getName()
            : null;
    }

    /**
     * Return the persisted recipient representation.
     *
     * @return array{email: string, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
}
