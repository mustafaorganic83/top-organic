<div class="space-y-6" x-data>
  <div class="flex flex-wrap gap-2 items-center">
    <h1 class="text-xl font-semibold">لوحة المعلومات التنفيذية</h1>
    <div class="ms-auto flex gap-2 text-sm">
      <button wire:click="setInterval('daily')"   class="px-3 py-1 rounded border" :class="{'bg-gray-900 text-white':false}">يومي</button>
      <button wire:click="setInterval('weekly')"  class="px-3 py-1 rounded border">أسبوعي</button>
      <button wire:click="setInterval('monthly')" class="px-3 py-1 rounded border">شهري</button>
      <button wire:click="setInterval('yearly')"  class="px-3 py-1 rounded border">سنوي</button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="rounded-lg border bg-white p-4">
      <div class="text-sm text-gray-500">نسبة تكلفة الطعام</div>
      <div class="mt-2 text-2xl font-bold">{{ number_format(($summary['food_cost_pct'] ?? 0)*100, 2) }}%</div>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="text-sm text-gray-500">الربح الإجمالي</div>
      <div class="mt-2 text-2xl font-bold">{{ number_format($summary['gross_profit'] ?? 0, 2) }}</div>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="text-sm text-gray-500">نسبة الهدر</div>
      <div class="mt-2 text-2xl font-bold">{{ number_format(($summary['waste_pct'] ?? 0)*100, 2) }}%</div>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="text-sm text-gray-500">قيمة المخزون</div>
      <div class="mt-2 text-2xl font-bold">{{ number_format($summary['inventory_value'] ?? 0, 2) }}</div>
    </div>
  </div>

  <!-- Top lists -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">أعلى المكونات كلفة</div>
      <ul class="divide-y">
        @foreach($topIngredients as $row)
          <li class="py-2 flex items-center justify-between">
            <span class="text-sm">#{{ $row['item_id'] }}</span>
            <span class="font-semibold">{{ number_format($row['value'], 2) }}</span>
          </li>
        @endforeach
      </ul>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">أعلى الوصفات كلفة</div>
      <ul class="divide-y">
        @foreach($topRecipes as $row)
          <li class="py-2 flex items-center justify-between">
            <span class="text-sm">#{{ $row['variant_id'] }}</span>
            <span class="font-semibold">{{ number_format($row['cogs'], 2) }}</span>
          </li>
        @endforeach
      </ul>
    </div>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" x-data="{ cost: @js($costTrend), waste: @js($wasteTrend), purch: @js($purchaseTrend), prod: @js($productionTrend) }"
       x-init="renderAll()" @charts:reload.window="renderAll()">
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">اتجاه التكلفة</div>
      <canvas id="chart-cost" height="120"></canvas>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">اتجاه الهدر</div>
      <canvas id="chart-waste" height="120"></canvas>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">اتجاه المشتريات</div>
      <canvas id="chart-purch" height="120"></canvas>
    </div>
    <div class="rounded-lg border bg-white p-4">
      <div class="mb-2 font-semibold">اتجاه الإنتاج</div>
      <canvas id="chart-prod" height="120"></canvas>
    </div>
  </div>

  <script>
    function renderLine(elId, series){
      const ctx = document.getElementById(elId); if(!ctx) return;
      const labels = series.map(r=>r.bucket); const data = series.map(r=>r.value);
      new Chart(ctx, { type:'line', data:{ labels, datasets:[{ label:'', data, borderColor:'#0ea5e9', fill:false }] }, options:{ responsive:true, plugins:{legend:{display:false}} } });
    }
    function renderAll(){
      const root = document.querySelector('[x-data]'); if(!root) return;
      const comp = Alpine.$data(root); if(!comp) return;
      ['chart-cost','chart-waste','chart-purch','chart-prod'].forEach(id=>{ const c=document.getElementById(id); if(c&&c._chart){c._chart.destroy();} });
      renderLine('chart-cost', comp.cost); renderLine('chart-waste', comp.waste); renderLine('chart-purch', comp.purch); renderLine('chart-prod', comp.prod);
    }
  </script>
</div>

