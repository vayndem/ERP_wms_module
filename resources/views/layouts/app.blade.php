<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="login-url" content="{{ route('login') }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <script>
        (() => {
            const saved = localStorage.getItem('inventory-theme');
            const theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'wms-dark' : 'wms');
        })();
    </script>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/images/icon/favicon.svg') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('assets/vendor/remixicon/fonts/remixicon.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body class="min-h-screen">
    <div id="loading">
        <div id="loading-center"></div>
    </div>
    <div class="wrapper">
        @include('layouts.template.sidebar')
        @include('layouts.template.navbar')

        @yield('content')
        @include('layouts.template.page-help')
    </div>

    @include('layouts.template.footer')
</body>

</html>
