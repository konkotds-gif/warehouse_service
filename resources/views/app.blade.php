<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0A1230">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icon.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <title>{{ config('app.name') }}</title>
    @inertiaHead
</head>
<body class="bg-bg text-ink-900 antialiased">
    @inertia
</body>
</html>
