<div>
    @include('menu.partials.flash')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-slate-900">
            {{ $productId === null ? __('menu.dishes.create') : __('menu.dishes.edit') }}
        </h1>

        <a href="{{ route('dishes.index') }}" wire:navigate class="text-sm text-slate-600 hover:underline">
            {{ __('menu.dishes.title') }}
        </a>
    </div>

    <form wire:submit="save" class="rounded-lg border border-slate-200 bg-white p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.sku') }}</label>
                <input type="text" wire:model="sku" @disabled($productId !== null)
                       class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">
                @error('sku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.name') }}</label>
                <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.category') }}</label>
                <select wire:model="categoryId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.status') }}</label>
                <select wire:model="status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="active">{{ __('menu.status.active') }}</option>
                    <option value="inactive">{{ __('menu.status.inactive') }}</option>
                </select>
                @error('status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.calories') }}</label>
                <input type="number" min="0" wire:model="calories"
                       class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('calories') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.sort_order') }}</label>
                <input type="number" min="0" wire:model="sortOrder"
                       class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('sortOrder') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('menu.dishes.description') }}</label>
                <textarea wire:model="description" rows="3"
                          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-6 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isSellable" class="rounded border-slate-300 text-emerald-600">
                    {{ __('menu.dishes.is_sellable') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isMeal" class="rounded border-slate-300 text-emerald-600">
                    {{ __('menu.dishes.is_meal') }}
                </label>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                {{ __('menu.actions.save') }}
            </button>
        </div>
    </form>

    @if ($productId !== null)
        <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ __('menu.dishes.variants') }}</h2>

            <div class="grid gap-3 sm:grid-cols-4">
                <input type="text" wire:model="variantCode" placeholder="{{ __('menu.dishes.sku') }}"
                       class="tabular rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input type="text" wire:model="variantName" placeholder="{{ __('menu.dishes.name') }}"
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input type="text" wire:model="variantMealSize" placeholder="{{ __('menu.recipe.variant') }}"
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <button type="button" wire:click="addVariant"
                        class="rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                    {{ __('menu.actions.create') }}
                </button>
            </div>
            @error('variantCode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="mt-6 space-y-6">
                @forelse ($variants as $variant)
                    <div wire:key="variant-{{ $variant->id }}" class="rounded-lg border border-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-sm font-medium text-slate-800">
                                {{ $variant->name ?? $variant->code }}
                                <span class="tabular ms-2 text-xs text-slate-500">{{ $variant->code }}</span>
                            </div>
                            <button type="button" wire:click="deleteVariant('{{ $variant->id }}')"
                                    class="text-sm text-rose-600 hover:underline">{{ __('menu.actions.delete') }}</button>
                        </div>

                        <div class="p-4">
                            @livewire('menu.recipe-builder', ['variantId' => $variant->id], key('recipe-'.$variant->id))
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('menu.dishes.empty') }}</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
