@use('App\Modules\Menu\Support\MoneyFormatter')

<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-800">{{ __('menu.recipe.title') }}</h3>

        <span @class([
            'rounded-full px-2 py-1 text-xs',
            'bg-amber-100 text-amber-700' => $versionState === 'draft',
            'bg-sky-100 text-sky-700' => $versionState === 'published',
            'bg-emerald-100 text-emerald-700' => $versionState === 'active',
            'bg-slate-100 text-slate-600' => $versionState === 'archived',
        ])>{{ __('menu.recipe.'.$versionState) }}</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.recipe.yield_quantity') }}</label>
            <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="yieldQuantity"
                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @error('yieldQuantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.recipe.yield_unit') }}</label>
            <input type="text" wire:model="yieldUnit"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @error('yieldUnit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.recipe.waste') }}</label>
            <input type="number" step="0.01" min="0" max="100" wire:model.live.debounce.400ms="wastePercent"
                   class="tabular w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @error('wastePercent') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="mt-4 overflow-x-auto rounded-md border border-slate-200">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-3 py-2 text-start font-medium">{{ __('menu.recipe.component_type') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('menu.recipe.component') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('menu.recipe.quantity') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('menu.recipe.unit') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('menu.recipe.line_waste') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('menu.recipe.unit_cost') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('menu.recipe.line_cost') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($lines as $index => $line)
                    @php($priced = $costed['lines'][$index] ?? null)
                    <tr wire:key="line-{{ $index }}">
                        <td class="px-3 py-2">
                            <select wire:model.live="lines.{{ $index }}.component_type"
                                    class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <option value="stock_item">{{ __('menu.recipe.type_stock_item') }}</option>
                                <option value="semi_finished_product">{{ __('menu.recipe.type_semi_finished') }}</option>
                            </select>
                        </td>
                        <td class="px-3 py-2">
                            <select wire:model.live="lines.{{ $index }}.component_id"
                                    class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <option value="">—</option>
                                @foreach (($line['component_type'] ?? 'stock_item') === 'stock_item' ? $stockItems : $semiItems as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @error('lines.'.$index.'.component_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </td>
                        <td class="px-3 py-2">
                            <input type="number" step="0.000001" min="0" wire:model.live.debounce.400ms="lines.{{ $index }}.quantity"
                                   class="tabular w-24 rounded-md border border-slate-300 px-2 py-1 text-sm">
                        </td>
                        <td class="px-3 py-2">
                            <input type="text" wire:model="lines.{{ $index }}.unit"
                                   class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                        </td>
                        <td class="px-3 py-2">
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="lines.{{ $index }}.waste_percent"
                                   class="tabular w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                        </td>
                        <td class="tabular px-3 py-2 text-end text-slate-600">
                            {{ MoneyFormatter::amount($priced['unit_cost_amount'] ?? null, $costed['currency']) }}
                        </td>
                        <td class="tabular px-3 py-2 text-end font-medium text-slate-800">
                            {{ MoneyFormatter::amount($priced['line_cost_amount'] ?? null, $costed['currency']) }}
                        </td>
                        <td class="px-3 py-2 text-end">
                            <button type="button" wire:click="removeLine({{ $index }})"
                                    class="text-xs text-rose-600 hover:underline">{{ __('menu.actions.remove_line') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @error('lines') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <button type="button" wire:click="addLine"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
            {{ __('menu.actions.add_line') }}
        </button>

        <div class="flex items-center gap-6 text-sm">
            <span class="text-slate-600">
                {{ __('menu.recipe.ingredient_cost') }}:
                <span class="tabular font-medium text-slate-900">{{ MoneyFormatter::money($costed['ingredient_cost'], $costed['currency']) }}</span>
            </span>
            <span class="text-slate-600">
                {{ __('menu.recipe.recipe_cost') }}:
                <span class="tabular font-semibold text-emerald-700">{{ MoneyFormatter::money($costed['recipe_cost'], $costed['currency']) }}</span>
            </span>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
        <button type="button" wire:click="save"
                class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
            {{ __('menu.actions.save') }}
        </button>

        @if ($versionId !== null && $versionState === 'draft')
            <button type="button" wire:click="publish"
                    class="rounded-md border border-sky-600 px-4 py-2 text-sm font-medium text-sky-700 hover:bg-sky-50">
                {{ __('menu.recipe.published') }}
            </button>
        @endif

        @if ($versionId !== null && $versionState === 'published')
            <button type="button" wire:click="activate"
                    class="rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                {{ __('menu.recipe.active') }}
            </button>
        @endif
    </div>
</div>
