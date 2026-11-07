<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#EEF1F2" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#151B20" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="いろどり手帳">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    {{-- タブのアイコン（葉っぱ）。SVG に対応していないブラウザは PNG、iPhone のホーム画面は apple-touch-icon --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+JP:wght@400;500;700&family=Pinyon+Script&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="@yield('shell', 'mx-auto max-w-[760px] pb-10')">
        @include('partials.brand')
        @yield('content')
    </div>

    <div id="toast" role="status" aria-live="polite" @class(['show' => session('status')])>{{ session('status') }}</div>
</body>
</html>
