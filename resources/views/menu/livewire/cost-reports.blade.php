<div>
    @include('menu.partials.flash')

    <h1 class="mb-4 text-xl font-semibold text-slate-900">{{ __('menu.reports.title') }}</h1>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.reports.title') }}</label>
                <select wire:model.live="kind" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="dish_cost">{{ __('menu.reports.dish_cost') }}</option>
                    <option value="ingredient_cost">{{ __('menu.reports.ingredient_cost') }}</option>
                    <option value="semi_finished_cost">{{ __('menu.reports.semi_finished_cost') }}</option>
                </select>
            </div>

            @if ($kind === 'dish_cost')
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('menu.dishes.category') }}</label>
                    <select wire:model.live="categoryId" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="">—</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="flex gap-2">
            <a href="{{ route('menu-reports.pdf', ['kind' => $kind, 'category' => $categoryId]) }}"
               class="rounded-md border border-rose-600 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                {{ __('menu.actions.export_pdf') }}
            </a>
            <a href="{{ route('menu-reports.excel', ['kind' => $kind, 'category' => $categoryId]) }}"
               class="rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                {{ __('menu.actions.export_excel') }}
            </a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    @foreach ($table->headings as $column => $heading)
                        <th @class([
                            'px-4 py-3 font-medium',
                            'text-end' => $table->isNumeric($column),
                            'text-start' => ! $table->isNumeric($column),
                        ])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($table->rows as $row)
                    <tr class="hover:bg-slate-50">
                        @foreach (array_values($row) as $column => $cell)
                            <td @class([
                                'px-4 py-2',
                                'tabular text-end text-slate-800' => $table->isNumeric($column),
                                'text-slate-600' => ! $table->isNumeric($column),
                            ])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($table->headings) }}" class="px-4 py-10 text-center text-slate-500">
                            {{ __('menu.reports.no_rows') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
