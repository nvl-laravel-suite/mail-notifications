@props([
    'type' => 'default',
    'align' => 'left',
])
@php
$variant = $type === 'success' ? 'success' : 'default';
$alignment = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
@endphp
<table class="panel panel-{{ $variant }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-content">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-item" align="{{ $alignment }}">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
</table>
