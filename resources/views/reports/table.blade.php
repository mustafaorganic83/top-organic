<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
    h3 { margin: 0 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
    th { background: #f5f5f5; }
  </style>
</head>
<body>
  <h3>{{ $title ?? 'Report' }}</h3>
  <table>
    <thead>
      <tr>
        @foreach($columns as $label)
          <th>{{ $label }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $row)
        <tr>
          @foreach(array_keys($columns) as $k)
            <td>{{ $row[$k] ?? '' }}</td>
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
