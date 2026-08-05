@props(['type' => 'info', 'label' => null])
@if(is_string($label) && trim($label) !== '')
[{{ trim($label) }}]
@endif
{{ $slot }}
