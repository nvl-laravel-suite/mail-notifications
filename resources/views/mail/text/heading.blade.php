@props(['subtitle' => null])
@if($subtitle)
{{ $subtitle }}
@endif
{{ $slot }}
