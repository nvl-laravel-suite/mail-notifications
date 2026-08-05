@props(['rows' => []])
@foreach($rows as $row)
{{ $row['label'] ?? '' }}: {{ strip_tags((string) ($row['value'] ?? '')) }}
@endforeach
