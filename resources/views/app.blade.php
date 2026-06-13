<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'TSMS') }}</title>
    <link rel="icon" type="image/png" href="/images/pitx_logo.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Styles / Scripts -->
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
    </style>

    <script>
        @auth
            window.authUser = @json(array_merge(
                auth()->user()->toArray(),
                ['roles' => auth()->user()->getRoleNames()->values()->toArray()]
            ));
        @else
            window.authUser = null;
        @endauth
        window.config = @json([
            'api_base' => url('/api'),
            'app_name' => config('app.name'),
        ]);
    </script>
</head>

<body class="antialiased">
    <div id="app"></div>
</body>

</html>
