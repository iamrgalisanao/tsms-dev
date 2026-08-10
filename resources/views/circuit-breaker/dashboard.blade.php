<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Transaction Monitoring Dashboard</title>

  {{-- Load assets --}}
  @php
    $isLocalViteHost = app()->environment('local') && in_array(request()->getHost(), ['localhost', '127.0.0.1', '::1'], true);
    $manifestPath = public_path('build/manifest.json');
    $manifest = (!$isLocalViteHost && is_file($manifestPath))
      ? json_decode(file_get_contents($manifestPath), true)
      : null;
    $appEntry = is_array($manifest) ? ($manifest['resources/js/app.js'] ?? null) : null;
  @endphp

  @if($isLocalViteHost)
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.js'])
  @elseif(is_array($appEntry))
    @foreach(($appEntry['imports'] ?? []) as $import)
      @if(isset($manifest[$import]['file']))
        <link rel="modulepreload" href="{{ asset('build/' . $manifest[$import]['file']) }}">
      @endif
    @endforeach
    @if(isset($manifest['resources/css/app.css']['file']))
      <link rel="stylesheet" href="{{ asset('build/' . $manifest['resources/css/app.css']['file']) }}">
    @endif
    <script type="module" src="{{ asset('build/' . $appEntry['file']) }}"></script>
  @else
    @vite(['resources/css/app.css', 'resources/js/app.js'])
  @endif
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
  <!-- <div id="app" style="min-height: 100vh;" data-page="dashboard"></div> -->

  {{-- Debug script before Vite --}}
  <script>
  console.log('Debug: Page loaded');
  document.getElementById('load-status').textContent = 'Page loaded';
  </script>
</body>

</html>
