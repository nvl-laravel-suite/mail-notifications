@props([
    'subtitle' => null,
    'level' => 2,
    'align' => 'left',
])
@php
$headingLevel = in_array((int) $level, [1, 2, 3], true) ? (int) $level : 2;
$alignment = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
@endphp
<table class="heading" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $alignment }}">
@if($subtitle)
<p class="heading-subtitle" style="text-align: {{ $alignment }};">{{ $subtitle }}</p>
@endif
@if($headingLevel === 1)
<h1 class="heading-title" style="text-align: {{ $alignment }};">{{ $slot }}</h1>
@elseif($headingLevel === 2)
<h2 class="heading-title" style="text-align: {{ $alignment }};">{{ $slot }}</h2>
@else
<h3 class="heading-title" style="text-align: {{ $alignment }};">{{ $slot }}</h3>
@endif
</td>
</tr>
</table>
