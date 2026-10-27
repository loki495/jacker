<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('letter.pdf_title') }}</title>
<style>
    @page { margin: 60px 70px; }
    body { font-family: 'DejaVu Serif', serif; font-size: 11pt; line-height: 1.5; color: #111; }
    .name { font-size: 16pt; font-weight: bold; margin: 0 0 4px; }
    .contact { margin: 0 0 28px; font-size: 9.5pt; color: #444; }
    .body { white-space: pre-wrap; margin: 0; }
</style>
</head>
<body>
    @if($contact['name'] ?? null)<p class="name">{{ $contact['name'] }}</p>@endif
    @if($lines)<p class="contact">{{ implode(' · ', $lines) }}</p>@endif
    <p class="body">{{ $letter->body }}</p>
</body>
</html>
