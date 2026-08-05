@props(['url'])
@php
$brand = $nvlMailBrand ?? [
    'name' => config('app.name'),
    'logo_url' => null,
    'logo_alt' => config('app.name'),
];
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" class="brand-link">
@if($brand['logo_url'] ?? null)
<img src="{{ $brand['logo_url'] }}" class="logo" alt="{{ $brand['logo_alt'] }}">
@else
<span class="brand-name">{{ $brand['name'] }}</span>
@endif
</a>
</td>
</tr>
