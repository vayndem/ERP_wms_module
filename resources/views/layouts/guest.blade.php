<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') - {{ config('app.name') }}</title>

    <script>
        (() => {
            const saved = localStorage.getItem('inventory-theme');
            const theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'wms-dark' : 'wms');
        })();
    </script>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/images/icon/favicon.svg') }}" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-base-200">
    <div id="loading">
        <div id="loading-center"></div>
    </div>

    <section class="flex min-h-screen items-center justify-center px-4 py-8">
        <div class="w-full max-w-md rounded-[2rem] border border-base-300 bg-base-100 p-7 shadow-2xl sm:p-10">
            <div class="mb-8 text-center">
                <span class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-[1.25rem] shadow-md @yield('icon-class', 'bg-primary text-primary-content')">
                    @yield('icon')
                </span>
                @yield('heading')
            </div>

            @if (session('status'))
                <div role="alert" class="alert alert-success mb-5 rounded-2xl text-sm">
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="alert alert-error mb-5 rounded-2xl text-sm">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </section>
</body>

</html>
