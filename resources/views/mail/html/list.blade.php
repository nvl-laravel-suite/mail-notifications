@props(['type' => 'bullet'])
@php
$variant = $type === 'numbered' ? 'numbered' : 'bullet';
@endphp
<table class="styled-list styled-list-{{ $variant }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="styled-list-content">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
