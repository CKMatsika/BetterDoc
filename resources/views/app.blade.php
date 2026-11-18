@php
    $branding = app(\App\Services\Branding\BrandingService::class)->toPublicPayload(auth()->user());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $branding['primary_color'] }}">
    <title inertia>{{ $branding['name'] }}</title>
    <link rel="icon" href="{{ $branding['favicon_url'] }}">
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body
    class="min-h-screen bg-[#F7F8FA] text-[#0F172A] antialiased selection:bg-sky-200 selection:text-slate-900"
    style="--brand-primary: {{ $branding['primary_color'] }}; --brand-primary-fg: {{ $branding['primary_fg'] }}; --brand-accent: {{ $branding['accent_color'] }}; --brand-accent-fg: {{ $branding['accent_fg'] }};"
>
    @inertia
</body>
</html>
