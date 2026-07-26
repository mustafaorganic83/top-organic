<div>
    @include('menu.partials.flash')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('menu.dishes.title') }}</h1>

        <a href="{{ route('dishes.create') }}" wire:navigate
           class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
            {{ __('menu.dishes.create') }}
        </a>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ __('menu.dishes.search_placeholder') }}"
               class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">

        <select wire:model.live="categoryId"
                class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">{{ __('menu.dishes.category') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="status"
                class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">{{ __('menu.dishes.status') }}</option>
            <option value="active">{{ __('menu.status.active') }}</option>
            <option value="inactive">{{ __('menu.status.inactive') }}</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('menu.dishes.sku') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('menu.dishes.name') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('menu.dishes.category') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('menu.dishes.variants') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('menu.dishes.status') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('menu.actions.edit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($dishes as $dish)
                    <tr wire:key="dish-{{ $dish->id }}" class="hover:bg-slate-50">
                        <td class="tabular px-4 py-3 text-slate-500">{{ $dish->sku }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $dish->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $dish->category?->name ?? '—' }}</td>
                        <td class="tabular px-4 py-3 text-slate-600">{{ $dish->variants->count() }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-1 text-xs',
                                'bg-emerald-100 text-emerald-700' => $dish->status === 'active',
                                'bg-slate-100 text-slate-600' => $dish->status !== 'active',
                            ])>{{ __('menu.status.'.$dish->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('dishes.edit', $dish) }}" wire:navigate
                               class="text-emerald-700 hover:underline">{{ __('menu.actions.edit') }}</a>
                            <button type="button" wire:click="confirmDelete('{{ $dish->id }}')"
                                    class="ms-3 text-rose-600 hover:underline">{{ __('menu.actions.delete') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('menu.dishes.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $dishes->links() }}</div>

    @if ($deletingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 class="mb-2 text-lg font-semibold text-slate-900">{{ __('menu.actions.confirm_delete') }}</h2>
                <p class="mb-6 text-sm text-slate-600">{{ __('menu.dishes.title') }}</p>

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelDelete"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm">{{ __('menu.actions.cancel') }}</button>
                    <button type="button" wire:click="delete"
                            class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                        {{ __('menu.actions.delete') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
