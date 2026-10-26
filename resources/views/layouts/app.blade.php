<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a href="/" wire:navigate class="brand"><span class="brand-mark">j.</span> {{ __('jobs.brand') }}</a>
            <div class="sidebar-caption">{{ __('jobs.workspace') }}</div>
            <nav aria-label="{{ __('jobs.navigation') }}">
                <a href="/" wire:navigate @class(['nav-link', 'selected' => request()->routeIs('home')])><span>▦</span>{{ __('jobs.overview') }}</a>
                <a href="/opportunities/create" wire:navigate class="nav-link"><span>＋</span>{{ __('jobs.add_job') }}</a>
                <a href="/import" wire:navigate class="nav-link"><span>↥</span>{{ __('jobs.import') }}</a>
                <a href="/link-checks" wire:navigate @class(['nav-link', 'selected' => request()->routeIs('link-checks')])><span>⟳</span>{{ __('jobs.nav_link_checks') }}</a>
                <a href="/job-finder" wire:navigate @class(['nav-link', 'selected' => request()->routeIs('job-finder')])><span>⌕</span>{{ __('scrapers.nav') }}</a>
                <a href="/scoring" wire:navigate @class(['nav-link', 'selected' => request()->routeIs('scoring')])><span>★</span>{{ __('jobs.nav_scoring') }}</a>
                <a href="/export" class="nav-link"><span>↧</span>{{ __('jobs.export') }}</a>
            </nav>
            <div class="sidebar-note"><span class="status-dot"></span>{{ __('jobs.private_workspace') }}<p>{{ __('jobs.manual_control') }}</p></div>
        </aside>
        <div class="main-shell">
            <header class="topbar"><span>{{ __('jobs.workspace') }} <span class="topbar-divider">/</span> {{ __('jobs.job_search') }}</span><span class="topbar-end"><flux:radio.group x-data variant="segmented" size="sm" x-model="$flux.appearance" :aria-label="__('jobs.appearance')"><flux:radio value="light" icon="sun" :aria-label="__('jobs.appearance_light')" /><flux:radio value="dark" icon="moon" :aria-label="__('jobs.appearance_dark')" /><flux:radio value="system" icon="computer-desktop" :aria-label="__('jobs.appearance_system')" /></flux:radio.group><span class="personal-label"><span class="avatar">A</span>{{ __('jobs.personal') }}</span></span></header>
            <main class="main-content">{{ $slot }}</main>
            <footer>{{ __('jobs.footer') }}</footer>
        </div>
    </div>
    @livewireScripts
    @fluxScripts
</body>
</html>
