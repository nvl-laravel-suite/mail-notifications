@props(['spacing' => 'md'])
@php
$size = in_array($spacing, ['sm', 'md', 'lg'], true) ? $spacing : 'md';
@endphp
<table class="divider divider-{{ $size }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<hr class="divider-line">
</td>
</tr>
</table>
