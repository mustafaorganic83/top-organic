<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $title ?? 'لوحة الإدارة' }}</title>
  @vite(['resources/css/app.css','resources/js/app.js'])
  @livewireStyles
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body class="min-h-dvh bg-gray-50 text-gray-900">
  <header class="sticky top-0 z-40 border-b bg-white/80 backdrop-blur">
    <div class="mx-auto max-w-7xl px-4 py-3 flex items-center gap-4">
      <div class="font-bold text-lg">ERP • توب أورجانيك</div>
      <nav class="ms-auto hidden md:flex gap-4 text-sm">
        <a href="{{ route('dashboard') }}" class="hover:text-primary-600">لوحة المعلومات</a>
        <a href="{{ route('reports') }}" class="hover:text-primary-600">التقارير</a>
        <a href="{{ route('inventory.cost') }}" class="hover:text-primary-600">تكلفة المخزون</a>
        <a href="{{ route('production') }}" class="hover:text-primary-600">الإنتاج</a>
        <a href="{{ route('recipes') }}" class="hover:text-primary-600">الوصفات</a>
        <a href="{{ route('prepared') }}" class="hover:text-primary-600">المحضّرات</a>
        <a href="{{ route('history') }}" class="hover:text-primary-600">سجل الإصدارات</a>
        <a href="{{ route('snapshots') }}" class="hover:text-primary-600">اللقطات</a>
        <a href="/docs" class="hover:text-primary-600">الوثائق (API)</a>
      </nav>
    </div>
  </header>
  <main class="mx-auto max-w-7xl px-4 py-6">
    {{ $slot ?? '' }}
    @yield('content')
  </main>
  @livewireScripts
  @livewireScriptConfig
</body>
</html>
