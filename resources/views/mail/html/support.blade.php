@if(! $slot->isEmpty() || filled($nvlMailBrand['support_text'] ?? null))
<table class="support" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="support-content">
{{ $slot->isEmpty() ? $nvlMailBrand['support_text'] : $slot }}
</td>
</tr>
</table>
@endif
