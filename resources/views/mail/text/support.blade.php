@if(! $slot->isEmpty() || filled($nvlMailBrand['support_text'] ?? null))
{{ $slot->isEmpty() ? $nvlMailBrand['support_text'] : $slot }}
@endif
