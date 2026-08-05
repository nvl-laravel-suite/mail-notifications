@php
$tokens = $nvlMailTheme ?? [
    'font_family' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
    'canvas' => '#f6f8fb',
    'surface' => '#ffffff',
    'text' => '#4b5563',
    'heading' => '#111827',
    'muted' => '#6b7280',
    'primary' => '#2563eb',
    'primary_hover' => '#1d4ed8',
    'primary_soft' => '#eff6ff',
    'accent' => '#7c3aed',
    'border' => '#e5e7eb',
    'info' => '#2563eb',
    'info_soft' => '#eff6ff',
    'success' => '#15803d',
    'success_soft' => '#f0fdf4',
    'warning' => '#a16207',
    'warning_soft' => '#fefce8',
    'danger' => '#b91c1c',
    'danger_soft' => '#fef2f2',
    'radius' => '14px',
    'component_radius' => '10px',
    'content_width' => '600px',
    'logo_max_width' => '200px',
    'logo_max_height' => '64px',
    'heading_1_size' => '28px',
    'heading_2_size' => '23px',
    'heading_3_size' => '18px',
    'subtitle_size' => '13px',
    'body_size' => '15px',
    'small_size' => '12px',
];
@endphp
body,
body *:not(html):not(style):not(br):not(tr):not(code) {
box-sizing: border-box;
font-family: {!! $tokens['font_family'] !!};
position: relative;
}

body {
-webkit-text-size-adjust: none;
background-color: {{ $tokens['canvas'] }};
color: {{ $tokens['text'] }};
height: 100%;
line-height: 1.55;
margin: 0;
padding: 0;
width: 100% !important;
}

.preheader {
color: transparent;
display: none;
height: 0;
max-height: 0;
max-width: 0;
opacity: 0;
overflow: hidden;
visibility: hidden;
width: 0;
}

p,
ul,
ol,
blockquote {
line-height: 1.55;
text-align: left;
}

a {
color: {{ $tokens['primary'] }};
}

a img {
border: none;
}

h1,
h2,
h3 {
color: {{ $tokens['heading'] }};
font-weight: 700;
letter-spacing: -0.02em;
margin-top: 0;
text-align: left;
}

h1 {
font-size: {{ $tokens['heading_1_size'] }};
line-height: 1.2;
}

h2 {
font-size: {{ $tokens['heading_2_size'] }};
line-height: 1.25;
}

h3 {
font-size: {{ $tokens['heading_3_size'] }};
line-height: 1.3;
}

p {
font-size: {{ $tokens['body_size'] }};
line-height: 1.6em;
margin-top: 0;
text-align: left;
}

p.sub {
font-size: {{ $tokens['small_size'] }};
}

img {
max-width: 100%;
}

.wrapper,
.body {
background-color: {{ $tokens['canvas'] }};
margin: 0;
padding: 0;
width: 100%;
}

.content {
margin: 0;
padding: 0;
width: 100%;
}

.header {
padding: 32px 0 24px;
text-align: center;
}

.brand-link {
display: inline-block;
text-decoration: none;
}

.brand-name {
color: {{ $tokens['heading'] }};
font-size: 20px;
font-weight: 750;
letter-spacing: -0.02em;
}

.logo {
height: auto;
max-height: {{ $tokens['logo_max_height'] }};
max-width: {{ $tokens['logo_max_width'] }};
width: auto;
}

.body-cell {
border: hidden !important;
padding: 0 16px;
}

.inner-body {
background-color: {{ $tokens['surface'] }};
border: 1px solid {{ $tokens['border'] }};
border-radius: {{ $tokens['radius'] }};
box-shadow: 0 12px 34px rgba(17, 24, 39, 0.08);
margin: 0 auto;
overflow: hidden;
padding: 0;
width: {{ $tokens['content_width'] }};
}

.content-cell {
max-width: 100vw;
padding: 40px 42px;
}

.content-cell > *:first-child {
margin-top: 0;
}

.content-cell > *:last-child {
margin-bottom: 0;
}

.footer {
margin: 0 auto;
padding: 24px 12px 32px;
text-align: center;
width: {{ $tokens['content_width'] }};
}

.footer-cell {
color: {{ $tokens['muted'] }};
font-size: {{ $tokens['small_size'] }};
line-height: 1.6;
text-align: center;
}

.footer p {
color: {{ $tokens['muted'] }};
font-size: {{ $tokens['small_size'] }};
text-align: center;
}

.footer a {
color: {{ $tokens['muted'] }};
text-decoration: underline;
}

.action {
margin: 30px auto;
padding: 0;
text-align: center;
width: 100%;
}

.button {
-webkit-text-size-adjust: none;
border-radius: {{ $tokens['component_radius'] }};
box-shadow: 0 5px 14px rgba(17, 24, 39, 0.14);
color: {{ $tokens['surface'] }};
display: inline-block;
font-size: 14px;
font-weight: 700;
letter-spacing: 0.01em;
overflow: hidden;
padding: 12px 22px;
text-decoration: none;
}

.button-primary {
background-color: {{ $tokens['primary'] }};
border-bottom: 10px solid {{ $tokens['primary'] }};
border-left: 18px solid {{ $tokens['primary'] }};
border-right: 18px solid {{ $tokens['primary'] }};
border-top: 10px solid {{ $tokens['primary'] }};
}

.button-success {
background-color: {{ $tokens['success'] }};
border-bottom: 10px solid {{ $tokens['success'] }};
border-left: 18px solid {{ $tokens['success'] }};
border-right: 18px solid {{ $tokens['success'] }};
border-top: 10px solid {{ $tokens['success'] }};
}

.button-danger {
background-color: {{ $tokens['danger'] }};
border-bottom: 10px solid {{ $tokens['danger'] }};
border-left: 18px solid {{ $tokens['danger'] }};
border-right: 18px solid {{ $tokens['danger'] }};
border-top: 10px solid {{ $tokens['danger'] }};
}

.panel,
.alert,
.data-table {
border-radius: {{ $tokens['component_radius'] }};
margin: 24px 0;
overflow: hidden;
}

.panel-content {
background-color: {{ $tokens['primary_soft'] }};
border-left: 4px solid {{ $tokens['primary'] }};
padding: 18px 20px;
}

.panel-item {
color: {{ $tokens['heading'] }};
}

.panel-item p:last-child,
.alert-content p:last-child {
margin-bottom: 0;
}

.panel-success .panel-content {
background-color: {{ $tokens['success_soft'] }};
border-left-color: {{ $tokens['success'] }};
}

.alert-content {
padding: 14px 18px;
}

.alert-default {
background-color: {{ $tokens['canvas'] }};
border: 1px solid {{ $tokens['border'] }};
color: {{ $tokens['text'] }};
}

.alert-info {
background-color: {{ $tokens['info_soft'] }};
border: 1px solid {{ $tokens['info'] }};
color: {{ $tokens['info'] }};
}

.alert-success {
background-color: {{ $tokens['success_soft'] }};
border: 1px solid {{ $tokens['success'] }};
color: {{ $tokens['success'] }};
}

.alert-warning {
background-color: {{ $tokens['warning_soft'] }};
border: 1px solid {{ $tokens['warning'] }};
color: {{ $tokens['warning'] }};
}

.alert-danger {
background-color: {{ $tokens['danger_soft'] }};
border: 1px solid {{ $tokens['danger'] }};
color: {{ $tokens['danger'] }};
}

.subcopy {
border-top: 1px solid {{ $tokens['border'] }};
margin-top: 32px;
padding-top: 24px;
}

.subcopy p {
color: {{ $tokens['muted'] }};
font-size: {{ $tokens['subtitle_size'] }};
line-height: 1.55;
}

.table table,
.data-table {
-premailer-cellpadding: 0;
-premailer-cellspacing: 0;
-premailer-width: 100%;
border-collapse: collapse;
margin: 24px auto;
width: 100%;
}

.table th,
.data-table-label {
background-color: {{ $tokens['canvas'] }};
color: {{ $tokens['heading'] }};
font-weight: 700;
padding: 11px 14px;
text-align: left;
}

.table td,
.data-table-value {
border-bottom: 1px solid {{ $tokens['border'] }};
color: {{ $tokens['text'] }};
font-size: 14px;
padding: 11px 14px;
text-align: left;
}

.data-table-label {
border-bottom: 1px solid {{ $tokens['border'] }};
width: 36%;
}

.heading {
margin: 0 0 20px;
}

.heading-title {
margin-bottom: 8px;
}

.heading-subtitle {
color: {{ $tokens['accent'] }};
font-size: {{ $tokens['subtitle_size'] }};
font-weight: 750;
letter-spacing: 0.08em;
margin: 0 0 6px;
text-transform: uppercase;
}

.divider {
margin: 0;
}

.divider-sm {
padding: 12px 0;
}

.divider-md {
padding: 20px 0;
}

.divider-lg {
padding: 30px 0;
}

.divider-line {
border: 0;
border-top: 1px solid {{ $tokens['border'] }};
margin: 0;
}

.support {
margin-top: 22px;
}

.support-content {
color: {{ $tokens['muted'] }};
font-size: {{ $tokens['subtitle_size'] }};
line-height: 1.55;
}

.styled-list {
margin: 18px 0;
}

.styled-list-content {
color: {{ $tokens['text'] }};
font-size: {{ $tokens['body_size'] }};
}

.two-column {
margin: 20px 0;
}

code {
background-color: {{ $tokens['canvas'] }};
border-radius: 5px;
color: {{ $tokens['danger'] }};
font-size: {{ $tokens['subtitle_size'] }};
padding: 2px 5px;
}

blockquote {
border-left: 4px solid {{ $tokens['border'] }};
color: {{ $tokens['muted'] }};
margin-left: 0;
padding-left: 16px;
}
