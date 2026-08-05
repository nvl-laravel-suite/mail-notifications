<x-mail::layout>
@if($nvlMailBrand['header_enabled'] ?? true)
<x-slot:header>
<x-mail::header :url="$nvlMailBrand['url'] ?? config('app.url')">
{{ $nvlMailBrand['name'] ?? config('app.name') }}
</x-mail::header>
</x-slot:header>
@endif

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

@if(($nvlMailBrand['footer_enabled'] ?? true) && filled($nvlMailBrand['footer_text'] ?? null))
<x-slot:footer>
<x-mail::footer>
{{ $nvlMailBrand['footer_text'] }}
</x-mail::footer>
</x-slot:footer>
@endif
</x-mail::layout>
