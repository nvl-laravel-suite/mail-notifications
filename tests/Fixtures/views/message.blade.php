<x-nvl-mail-notifications::message>
<x-nvl-mail-notifications::heading subtitle="Package mail" :level="1">
Tracked delivery
</x-nvl-mail-notifications::heading>

This message verifies the tokenized Laravel Markdown presentation.

<x-nvl-mail-notifications::button
    url="https://example.test/action"
    :color="$buttonColor ?? 'primary'"
    :align="$buttonAlign ?? 'center'"
>
Continue
</x-nvl-mail-notifications::button>

<x-nvl-mail-notifications::panel type="success">
Provider-neutral mail remains compatible with Laravel transports.
</x-nvl-mail-notifications::panel>

<x-nvl-mail-notifications::alert :type="$alertType ?? 'warning'">
Delivery details remain application-owned.
</x-nvl-mail-notifications::alert>

<x-nvl-mail-notifications::data-table :rows="$rows ?? []" />

<x-nvl-mail-notifications::support />

<x-nvl-mail-notifications::divider spacing="sm" />

<x-nvl-mail-notifications::list type="numbered">
1. Transport-neutral
2. Application-owned
</x-nvl-mail-notifications::list>

<x-nvl-mail-notifications::table>
| Capability | State |
| :-- | :-- |
| Tracking | Opt-in |
</x-nvl-mail-notifications::table>

<x-nvl-mail-notifications::two-column :gap="16">
<x-slot:left>
HTML and text
</x-slot:left>
<x-slot:right>
Responsive layout
</x-slot:right>
</x-nvl-mail-notifications::two-column>

<x-slot:subcopy>
This is generic supporting copy.
</x-slot:subcopy>
</x-nvl-mail-notifications::message>
