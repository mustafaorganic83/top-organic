<div>
    @include('menu.partials.flash')

    <h1 class="mb-4 text-xl font-semibold text-slate-900">{{ __('menu.categories.title') }}</h1>

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-1">
            <h2 class="mb-4 text-sm font-semibold text-slate-800">
                {{ $editingId === null ? __('menu.categories.create') : __('menu.categories.edit') }}
            </h2>

            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.code') }}</label>
                    <input type="text" wire:model="code" @disabled($editingId !== null)
                           class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">
                    @error('code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.name') }}</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.parent') }}</label>
                    <select wire:model="parentId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="">—</option>
                        @foreach ($categories as $option)
                            @continue($option->id === $editingId)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('parentId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.description') }}</label>
                    <textarea wire:model="description" rows="2"
                              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
                    @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.sort_order') }}</label>
                        <input type="number" min="0" wire:model="sortOrder"
                               class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('sortOrder') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.categories.status') }}</label>
                        <select wire:model="status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="active">{{ __('menu.status.active') }}</option>
                            <option value="inactive">{{ __('menu.status.inactive') }}</option>
                        </select>
                        @error('status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                @if ($editingId !== null)
                    <button type="button" wire:click="cancel"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm">{{ __('menu.actions.cancel') }}</button>
                @endif
                <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    {{ __('menu.actions.save') }}
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white lg:col-span-2">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('menu.categories.code') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('menu.categories.name') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('menu.categories.parent') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('menu.categories.products_count') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('menu.categories.status') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('menu.actions.edit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($categories as $category)
                        <tr wire:key="category-{{ $category->id }}" class="hover:bg-slate-50">
                            <td class="tabular px-4 py-3 text-slate-500">{{ $category->code }}</td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $categories->firstWhere('id', $category->parent_id)?->name ?? '—' }}
                            </td>
                            <td class="tabular px-4 py-3 text-slate-600">{{ $category->products_count }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-1 text-xs',
                                    'bg-emerald-100 text-emerald-700' => $category->status === 'active',
                                    'bg-slate-100 text-slate-600' => $category->status !== 'active',
                                ])>{{ __('menu.status.'.$category->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <button type="button" wire:click="edit('{{ $category->id }}')"
                                        class="text-emerald-700 hover:underline">{{ __('menu.actions.edit') }}</button>
                                <button type="button" wire:click="confirmDelete('{{ $category->id }}')"
                                        class="ms-3 text-rose-600 hover:underline">{{ __('menu.actions.delete') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('menu.categories.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($deletingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 class="mb-2 text-lg font-semibold text-slate-900">{{ __('menu.actions.confirm_delete') }}</h2>
                <p class="mb-6 text-sm text-slate-600">
                    {{ $categories->firstWhere('id', $deletingId)?->name }}
                </p>

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
