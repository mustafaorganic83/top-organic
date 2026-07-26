@php($rtl = in_array(app()->getLocale(), config('region.rtl_locales', ['ar']), true))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('messages.app_name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
                <a href="{{ route('dishes.index') }}" class="text-lg font-semibold text-emerald-700">
                    {{ __('messages.app_name') }}
                </a>

                <nav class="flex items-center gap-1 text-sm">
                    @foreach ([
                        'dishes.index' => __('menu.nav.dishes'),
                        'dish-categories.index' => __('menu.nav.categories'),
                        'ingredients.index' => __('menu.nav.ingredients'),
                        'menu-reports.index' => __('menu.nav.reports'),
                    ] as $route => $label)
                        <a href="{{ route($route) }}"
                           @class([
                               'rounded-md px-3 py-2 transition',
                               'bg-emerald-50 font-semibold text-emerald-700' => request()->routeIs($route),
                               'text-slate-600 hover:bg-slate-100' => ! request()->routeIs($route),
                           ])>{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-3 text-sm text-slate-500">
                    @if ($branch = request()->attributes->get('active_branch'))
                        <span class="rounded-md bg-slate-100 px-2 py-1">{{ $branch->name }}</span>
                    @endif
                    <span>{{ auth('web')->user()?->name }}</span>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
