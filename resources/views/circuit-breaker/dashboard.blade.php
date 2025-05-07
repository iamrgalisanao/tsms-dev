<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Circuit Breaker Dashboard</title>
    
    {{-- Add this line to ensure Vite integration --}}
    @viteReactRefresh

    {{-- Load assets --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100">
    {{-- Add loading state --}}
    <div id="debug-info" class="fixed top-0 left-0 right-0 bg-yellow-100 p-2 text-sm">
        Loading status: <span id="load-status">Initializing...</span>
    </div>

    <div id="app">
        <div class="flex items-center justify-center min-h-screen">
            <div class="text-gray-600">Loading dashboard...</div>
        </div>
    </div>

    {{-- Debug script before Vite --}}
    <script>
        console.log('Debug: Page loaded');
        document.getElementById('load-status').textContent = 'Page loaded';
    </script>
</body>
</html>
