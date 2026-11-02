<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $name ?? __('letter.pdf_title') }}</title>
<style>
    @font-face { font-family: 'Liberation Sans'; font-weight: normal; font-style: normal; src: url('{{ $fontDir }}/LiberationSans-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'Liberation Sans'; font-weight: bold; font-style: normal; src: url('{{ $fontDir }}/LiberationSans-Bold.ttf') format('truetype'); }
    @font-face { font-family: 'Liberation Sans'; font-weight: normal; font-style: italic; src: url('{{ $fontDir }}/LiberationSans-Italic.ttf') format('truetype'); }
    @font-face { font-family: 'Liberation Sans'; font-weight: bold; font-style: italic; src: url('{{ $fontDir }}/LiberationSans-BoldItalic.ttf') format('truetype'); }
    body { font-family: 'Liberation Sans', sans-serif; color: #111; }
    a { color: #111; text-decoration: none; }
    .name { font-weight: bold; margin: 0 0 2px; }
    .contact { color: #333; margin: 0; }
    hr { border: 0; border-top: 0.5pt solid #444; margin: 5px 0; }
@if($kind === 'resume')
    @page { margin: 36px 44px; }
    body { font-size: 9.5pt; line-height: 1.25; }
    .name { font-size: 18pt; text-align: center; }
    .contact { font-size: 9pt; text-align: center; margin-bottom: 6px; }
    h1 { font-size: 13pt; margin: 8px 0 3px; }
    h2 { font-size: 11pt; border-bottom: 0.5pt solid #444; margin: 9px 0 3px; padding-bottom: 1px; text-transform: uppercase; }
    h3 { font-size: 10pt; margin: 6px 0 1px; }
    p { margin: 0 0 3px; }
    ul { margin: 1px 0 4px; padding-left: 16px; }
    li { margin: 0 0 1px; }
@else
    @page { margin: 64px 72px; }
    body { font-size: 11pt; line-height: 1.45; }
    .name { font-size: 16pt; }
    .contact { font-size: 9.5pt; margin-bottom: 26px; }
    h1, h2, h3 { font-size: 11pt; margin: 12px 0 4px; }
    p { margin: 0 0 10px; }
    ul { margin: 0 0 10px; padding-left: 18px; }
@endif
</style>
</head>
<body>
    @if($name)<p class="name">{{ $name }}</p>@endif
    @if($lines)<p class="contact">{{ implode(' | ', $lines) }}</p>@endif
    {!! $html !!}
</body>
</html>
