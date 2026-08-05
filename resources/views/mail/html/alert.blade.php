@props(['type' => 'info'])
@php
$variant = match ($type) {
    'default' => 'default',
    'success' => 'success',
    'warning' => 'warning',
    'danger', 'error' => 'danger',
    default => 'info',
};
@endphp
<table class="alert alert-{{ $variant }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="alert-content">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
