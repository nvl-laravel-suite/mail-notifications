<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $nvlMailBrand['name'] ?? config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 640px) {
.inner-body,
.footer {
width: 100% !important;
}

.body-cell {
padding-left: 12px !important;
padding-right: 12px !important;
}

.content-cell {
padding: 30px 24px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
box-sizing: border-box !important;
text-align: center !important;
width: 100% !important;
}

.two-column-left,
.two-column-right {
display: block !important;
padding-left: 0 !important;
padding-right: 0 !important;
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>
<span class="preheader">{{ $preheader ?? '' }}</span>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<tr>
<td class="body body-cell" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>
{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
