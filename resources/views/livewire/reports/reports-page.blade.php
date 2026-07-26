@section('content')
<div class="space-y-4">
  <h1 class="text-xl font-semibold">التقارير</h1>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="col-span-1 space-y-2">
      <label class="block text-sm">التقرير</label>
      <select wire:model="report" class="w-full rounded border p-2">
        @foreach(app(\App\Reports\ReportManager::class)->list() as $r)
          <option value="{{ $r['key'] }}">{{ $r['name'] }}</option>
        @endforeach
      </select>
      <label class="block text-sm">التجميع</label>
      <select wire:model="groupBy" class="w-full rounded border p-2">
        <option value="">—</option>
        <option value="as_of_date">التاريخ</option>
        <option value="department_id">القسم</option>
        <option value="warehouse_id">المخزن</option>
      </select>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="block text-sm">من</label><input type="date" wire:model="dateFrom" class="w-full rounded border p-2" /></div>
        <div><label class="block text-sm">إلى</label><input type="date" wire:model="dateTo" class="w-full rounded border p-2" /></div>
      </div>
      <button wire:click="run" class="mt-2 rounded bg-sky-600 px-3 py-2 text-white">تشغيل</button>
      <div class="text-xs text-gray-500">التصدير متاح من API الحالية (CSV/Excel/PDF).</div>
    </div>
    <div class="col-span-3 overflow-auto rounded border bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            @foreach($result['columns'] as $label)
              <th class="px-3 py-2 text-start">{{ $label }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody class="divide-y">
          @foreach($result['rows'] as $row)
            <tr>
              @foreach(array_keys($result['columns']) as $k)
                <td class="px-3 py-2">{{ $row[$k] ?? '' }}</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
