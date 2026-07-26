@use('App\Modules\Menu\Support\MoneyFormatter')

<table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-600">
        <tr>
            <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.sku') }}</th>
            <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.name') }}</th>

            @if ($tab === 'stock')
                <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.kind') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.stock_unit') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('menu.ingredients.unit_cost') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('menu.ingredients.waste') }}</th>
            @else
                <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.yield_unit') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('menu.ingredients.yield_quantity') }}</th>
            @endif

            <th class="px-4 py-3 text-start font-medium">{{ __('menu.ingredients.status') }}</th>
            <th class="px-4 py-3 text-end font-medium">{{ __('menu.actions.edit') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($rows as $row)
            <tr wire:key="ingredient-{{ $row->id }}" class="hover:bg-slate-50">
                <td class="tabular px-4 py-3 text-slate-500">{{ $row->sku }}</td>
                <td class="px-4 py-3 font-medium text-slate-900">{{ $row->name }}</td>

                @if ($tab === 'stock')
                    <td class="px-4 py-3 text-slate-600">{{ __('menu.ingredients.kind_'.$row->kind) }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ $row->stock_unit }}</td>
                    <td class="tabular px-4 py-3 text-end text-slate-800">
                        {{ MoneyFormatter::money($row->unit_cost_amount, $row->currency) }}
                    </td>
                    <td class="tabular px-4 py-3 text-end text-slate-600">
                        {{ MoneyFormatter::percent($row->default_waste_bps) }}
                    </td>
                @else
                    <td class="px-4 py-3 text-slate-600">{{ $row->yield_unit }}</td>
                    <td class="tabular px-4 py-3 text-end text-slate-800">{{ $row->yield_quantity }}</td>
                @endif

                <td class="px-4 py-3">
                    <span @class([
                        'rounded-full px-2 py-1 text-xs',
                        'bg-emerald-100 text-emerald-700' => $row->status === 'active',
                        'bg-slate-100 text-slate-600' => $row->status !== 'active',
                    ])>{{ __('menu.status.'.$row->status) }}</span>
                </td>

                <td class="px-4 py-3 text-end">
                    <button type="button" wire:click="edit('{{ $row->id }}')"
                            class="text-emerald-700 hover:underline">{{ __('menu.actions.edit') }}</button>
                    <button type="button" wire:click="confirmDelete('{{ $row->id }}')"
                            class="ms-3 text-rose-600 hover:underline">{{ __('menu.actions.delete') }}</button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $tab === 'stock' ? 8 : 6 }}" class="px-4 py-10 text-center text-slate-500">
                    {{ __('menu.ingredients.empty') }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
