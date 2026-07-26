<?php

namespace App\Http\Controllers;

use App\Reports\DTOs\ReportRequest;
use App\Reports\ReportManager;
use App\Reports\Export\CsvExporter;
use App\Reports\Export\ExcelExporter;
use App\Reports\Export\PdfExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(private ReportManager $reports)
    {
    }

    public function index()
    {
        return response()->json($this->reports->list());
    }

    public function run(Request $request, string $key)
    {
        [$filters, $groupBy, $drillKey, $format] = $this->parseRequest($request);
        $req = new ReportRequest($filters, $groupBy, $drillKey, $format);
        $result = $this->reports->run($key, $req);

        $format = strtolower($format ?? 'json');
        if ($format === 'json') {
            return response()->json(['columns'=>$result->columns,'rows'=>$result->rows,'totals'=>$result->totals,'meta'=>$result->meta]);
        }
        if (in_array($format, ['csv','excel'])) {
            $exporter = $format==='excel' ? new ExcelExporter() : new CsvExporter();
            $payload = $exporter->export($result, ['title'=>$key]);
            $filename = $key.'.'.($format==='excel'?'csv':'csv');
            return new Response($payload, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }
        if ($format === 'pdf') {
            $exporter = new PdfExporter();
            $payload = $exporter->export($result, ['title'=>$key]);
            $isPdf = str_starts_with($payload, '%PDF-');
            return new Response($payload, 200, [
                'Content-Type' => $isPdf ? 'application/pdf' : 'text/html; charset=UTF-8',
                'Content-Disposition' => 'inline; filename="'.$key.'.pdf"',
            ]);
        }
        return response()->json(['error'=>'Unsupported format'], 400);
    }

    public function drilldown(Request $request, string $key)
    {
        $groupField = $request->query('group_field');
        $groupValue = $request->query('group_value');
        [$filters, $groupBy, $drillKey, $format] = $this->parseRequest($request);
        if ($groupField && $groupValue !== null) {
            $filters[$groupField] = $groupValue;
        }
        $req = new ReportRequest($filters, null, $groupValue, $format);
        $result = $this->reports->run($key, $req);
        return response()->json(['columns'=>$result->columns,'rows'=>$result->rows,'totals'=>$result->totals,'meta'=>$result->meta]);
    }

    private function parseRequest(Request $r): array
    {
        $groupBy = $r->query('group_by');
        $drillKey = $r->query('drill_key');
        $format = strtolower($r->query('format', 'json'));
        $reserved = ['group_by','drill_key','format'];
        $filters = collect($r->query())->reject(fn($v,$k)=>in_array($k,$reserved,true))->all();
        return [$filters, $groupBy, $drillKey, $format];
    }
}
