<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Reports\ExcelReportWriter;
use App\Modules\Menu\Reports\PdfReportWriter;
use App\Modules\Menu\Reports\ReportTable;
use App\Modules\Menu\Reports\ReportTableFactory;
use App\Support\Context\AppContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF and Excel downloads for the costing reports. A plain controller rather
 * than a Livewire action because the browser needs a real file response; the
 * table itself is built by the same factory the on-screen preview uses.
 */
class CostReportController extends Controller
{
    private const KINDS = ['dish_cost', 'ingredient_cost', 'semi_finished_cost'];

    public function pdf(Request $request, string $kind, ReportTableFactory $factory, PdfReportWriter $writer): Response
    {
        $table = $this->table($request, $kind, $factory);

        return $this->download(
            $writer->render($table, $this->meta($request)),
            $this->filename($table, 'pdf'),
            'application/pdf',
        );
    }

    public function excel(Request $request, string $kind, ReportTableFactory $factory, ExcelReportWriter $writer): Response
    {
        $table = $this->table($request, $kind, $factory);

        return $this->download(
            $writer->render($table, $this->meta($request)),
            $this->filename($table, 'xlsx'),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    private function table(Request $request, string $kind, ReportTableFactory $factory): ReportTable
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $category = $request->query('category');

        return $factory->make(
            $this->context($request),
            $kind,
            is_string($category) && $category !== '' ? $category : null,
        );
    }

    /** Scope comes from the resolved context, never from the query string. */
    private function context(Request $request): MenuContext
    {
        $app = app(AppContext::class);
        $user = $request->user('web');

        abort_unless($user instanceof User && $app->tenantId() !== null && $app->branchId() !== null, 403);

        return new MenuContext($app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId());
    }

    /** @return array<string, string> */
    private function meta(Request $request): array
    {
        $branch = $request->attributes->get('active_branch');

        return array_filter([
            __('menu.reports.branch') => is_object($branch) ? (string) $branch->name : '',
            __('menu.reports.generated_at') => Carbon::now(config('region.timezone'))->format('Y-m-d H:i'),
        ]);
    }

    private function filename(ReportTable $table, string $extension): string
    {
        $slug = Str::slug($table->title);

        return ($slug !== '' ? $slug : 'report').'-'.Carbon::now()->format('Ymd-His').'.'.$extension;
    }

    private function download(string $contents, string $filename, string $mime): Response
    {
        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
