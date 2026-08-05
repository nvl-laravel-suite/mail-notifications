@props(['gap' => 20])
@php
$columnGap = is_numeric($gap) ? min(max((float) $gap, 0), 48) : 20;
$columnPadding = $columnGap / 2;
@endphp
<table class="two-column" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="two-column-left" width="50%" valign="top" style="padding-right: {{ $columnPadding }}px;">
{{ $left ?? '' }}
</td>
<td class="two-column-right" width="50%" valign="top" style="padding-left: {{ $columnPadding }}px;">
{{ $right ?? '' }}
</td>
</tr>
</table>
