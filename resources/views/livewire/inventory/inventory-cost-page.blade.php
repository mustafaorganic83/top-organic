@section('content')
<div class="space-y-4">
  <h1 class="text-xl font-semibold">&#x062A;&#x0643;&#x0644;&#x0641;&#x0629; &#x0627;&#x0644;&#x0645;&#x062E;&#x0632;&#x0648;&#x0646;</h1>
  <div class="rounded border bg-white overflow-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-3 py-2 text-start">&#x0627;&#x0644;&#x0645;&#x062E;&#x0632;&#x0646;</th>
          <th class="px-3 py-2 text-start">&#x0627;&#x0644;&#x0646;&#x0648;&#x0639;</th>
          <th class="px-3 py-2 text-start">&#x0627;&#x0644;&#x0645;&#x0639;&#x0631;&#x0651;&#x0641;</th>
          <th class="px-3 py-2 text-start">&#x0627;&#x0644;&#x0643;&#x0645;&#x064A;&#x0629;</th>
          <th class="px-3 py-2 text-start">&#x0627;&#x0644;&#x0642;&#x064A;&#x0645;&#x0629;</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        @foreach($rows as $r)
          <tr>
            <td class="px-3 py-2">{{ $r['warehouse_id'] }}</td>
            <td class="px-3 py-2">{{ $r['stockable_type'] }}</td>
            <td class="px-3 py-2">{{ $r['stockable_id'] }}</td>
            <td class="px-3 py-2">{{ number_format($r['qty'], 3) }}</td>
            <td class="px-3 py-2">{{ number_format($r['val'], 2) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
