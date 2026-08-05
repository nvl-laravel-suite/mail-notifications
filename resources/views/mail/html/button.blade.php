@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
$variant = match ($color) {
    'success', 'green' => 'success',
    'danger', 'error', 'red' => 'danger',
    default => 'primary',
};
$alignment = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
@endphp
<table class="action" align="{{ $alignment }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $alignment }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $alignment }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $variant }}" target="_blank" rel="noopener">{{ $slot }}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
