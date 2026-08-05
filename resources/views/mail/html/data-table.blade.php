@props(['rows' => []])
<table class="data-table" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach($rows as $row)
@continue(! is_array($row))
<tr class="data-table-row">
<td class="data-table-label">{{ $row['label'] ?? '' }}</td>
<td class="data-table-value">
@if(($row['html'] ?? false) === true)
{!! (string) ($row['value'] ?? '') !!}
@else
{{ $row['value'] ?? '' }}
@endif
</td>
</tr>
@endforeach
</table>
