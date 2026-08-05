<x-mail::message>
<x-mail::heading subtitle="Package mail" :level="1">
Tracked delivery
</x-mail::heading>

This message verifies the tokenized Laravel Markdown presentation.

<x-mail::button
    url="https://example.test/action"
    :color="$buttonColor ?? 'primary'"
    :align="$buttonAlign ?? 'center'"
>
Continue
</x-mail::button>

<x-mail::panel type="success">
Provider-neutral mail remains compatible with Laravel transports.
</x-mail::panel>

<x-mail::alert :type="$alertType ?? 'warning'">
Delivery details remain application-owned.
</x-mail::alert>

<x-mail::data-table :rows="$rows ?? []" />

<x-mail::support />

<x-mail::divider spacing="sm" />

<x-mail::list type="numbered">
1. Transport-neutral
2. Application-owned
</x-mail::list>

<x-mail::table>
| Capability | State |
| :-- | :-- |
| Tracking | Opt-in |
</x-mail::table>

<x-mail::two-column :gap="16">
<x-slot:left>
HTML and text
</x-slot:left>
<x-slot:right>
Responsive layout
</x-slot:right>
</x-mail::two-column>

<x-slot:subcopy>
This is generic supporting copy.
</x-slot:subcopy>
</x-mail::message>
