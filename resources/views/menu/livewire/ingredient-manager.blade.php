<div>
    @include('menu.partials.flash')

    <h1 class="mb-4 text-xl font-semibold text-slate-900">{{ __('menu.ingredients.title') }}</h1>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-md border border-slate-300 bg-white p-1">
            <button type="button" wire:click="$set('tab', 'stock')"
                    @class(['rounded px-3 py-1.5 text-sm', 'bg-emerald-600 text-white' => $tab === 'stock', 'text-slate-700' => $tab !== 'stock'])>
                {{ __('menu.ingredients.tab_stock') }}
            </button>
            <button type="button" wire:click="$set('tab', 'semi')"
                    @class(['rounded px-3 py-1.5 text-sm', 'bg-emerald-600 text-white' => $tab === 'semi', 'text-slate-700' => $tab !== 'semi'])>
                {{ __('menu.ingredients.tab_semi') }}
            </button>
        </div>

        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ __('menu.actions.search') }}"
               class="rounded-md border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-1">
            <h2 class="mb-4 text-sm font-semibold text-slate-800">
                {{ $editingId === null ? __('menu.ingredients.create') : __('menu.ingredients.edit') }}
            </h2>

            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.sku') }}</label>
                    <input type="text" wire:model="sku" @disabled($editingId !== null)
                           class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">
                    @error('sku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.name') }}</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                @if ($tab === 'stock')
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.kind') }}</label>
                        <select wire:model="kind" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="ingredient">{{ __('menu.ingredients.kind_ingredient') }}</option>
                            <option value="packaging">{{ __('menu.ingredients.kind_packaging') }}</option>
                        </select>
                        @error('kind') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.stock_unit') }}</label>
                            <input type="text" wire:model="stockUnit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @error('stockUnit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.currency') }}</label>
                            <input type="text" wire:model="currency" maxlength="3"
                                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase">
                            @error('currency') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.unit_cost') }}</label>
                            <input type="number" step="0.01" min="0" wire:model="unitCost"
                                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @error('unitCost') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.waste') }}</label>
                            <input type="number" step="0.01" min="0" wire:model="wastePercent"
                                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @error('wastePercent') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.yield_unit') }}</label>
                            <input type="text" wire:model="yieldUnit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @error('yieldUnit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.yield_quantity') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="yieldQuantity"
                                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @error('yieldQuantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.calories_per_unit') }}</label>
                        <input type="number" step="0.01" min="0" wire:model="caloriesPerUnit"
                               class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('caloriesPerUnit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.ingredients.status') }}</label>
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

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white lg:col-span-2">
            @include('menu.partials.ingredient-table', ['rows' => $rows, 'tab' => $tab])
        </div>
    </div>

    @if ($deletingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 class="mb-2 text-lg font-semibold text-slate-900">{{ __('menu.actions.confirm_delete') }}</h2>
                <p class="mb-6 text-sm text-slate-600">{{ $rows->firstWhere('id', $deletingId)?->name }}</p>

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
