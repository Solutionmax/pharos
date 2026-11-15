<?php

namespace App\Http\Controllers\PublicFeatures;

use App\Http\Controllers\Controller;
use App\Services\Branding;
use App\Services\MonthlyUptimeReport;
use App\Services\PageContext;
use App\Services\PublicComponents;
use App\Support\Csv;
use Closure;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{
    /** A finished month no longer moves; a day bounds what a late import or edit leaves stale. */
    public const FINISHED_MONTH_SECONDS = 86400;

    /** The month under way changes with every check, so its file is kept briefly. */
    public const CURRENT_MONTH_SECONDS = 600;

    public function show(Request $request, MonthlyUptimeReport $reports)
    {
        $admin = $request->is('admin/*');
        $month = $reports->month($request);
        $format = str_ends_with($request->path(), '.csv') ? 'csv' : (str_ends_with($request->path(), '.pdf') ? 'pdf' : 'html');
        if ($format === 'html') {
            $report = $reports->build($month, ! $admin);

            return view($admin ? 'admin.reports' : 'public.report', compact('report', 'admin'));
        }

        $render = fn () => $format === 'csv' ? $this->csv($reports->build($month, ! $admin)) : $this->pdf($reports->build($month, ! $admin));
        // A public PDF costs seconds of CPU; anyone can ask for it, so it is made
        // once per month, page, language and set of visible services. The admin
        // copy shows every service and is rendered fresh.
        $body = $admin ? $render() : $this->remember($format, $month, $render);
        $type = $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf';

        return response($body)->header('Content-Type', $type)
            ->header('Content-Disposition', 'attachment; filename="uptime-'.$month.'.'.$format.'"')
            ->header('Cache-Control', 'no-store');
    }

    /** @param  Closure(): string  $render */
    private function remember(string $format, string $month, Closure $render): string
    {
        $page = app(PageContext::class)->page();
        $visible = PublicComponents::query()->orderBy('position')->orderBy('id')->limit(501)->get(['id', 'name'])
            ->map(fn ($component) => [$component->id, $component->name])->all();
        // What the file shows besides the numbers: the services, the page name,
        // the accent colour and the release that drew it.
        $fingerprint = hash('sha256', (string) json_encode([$visible, $page->name, app(Branding::class)->accent(), config('pharos.version')]));
        $key = implode(':', ['pharos', 'report', $page->id, $month, app()->getLocale(), $format, $fingerprint]);
        $seconds = $month === now('UTC')->format('Y-m') ? self::CURRENT_MONTH_SECONDS : self::FINISHED_MONTH_SECONDS;

        // Base64: a database cache on MySQL stores text, and a PDF is binary.
        return (string) base64_decode((string) Cache::remember($key, $seconds, fn () => base64_encode($render())), true);
    }

    private function csv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Service', 'Up seconds', 'Down seconds', 'Uptime %', 'Coverage %', 'Excluded maintenance seconds', 'Unobserved seconds', 'Month', 'Timezone', 'Accounting notes'], ',', '"', '');
        foreach ($report['rows'] as $row) {
            fputcsv($stream, array_map(Csv::cell(...), [$row['name'], $row['up_seconds'], $row['down_seconds'], $row['uptime'] === null ? '' : number_format($row['uptime'], 2, '.', ''), $row['coverage'], $row['excluded_seconds'], $row['unobserved_seconds'], $report['month'], 'UTC', __('Historical daily totals may include maintenance observations. They are preserved and are not reconstructed.')]), ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function pdf(array $report): string
    {
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => [storage_path('app'), public_path('fonts')], 'tempDir' => storage_path('app'), 'fontDir' => storage_path('app'), 'fontCache' => storage_path('app')]);
        $pdf = new Dompdf($options);
        $pageName = app(PageContext::class)->page()->name;
        $pdf->loadHtml(view('public.report-pdf', compact('report', 'pageName'))->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('Plus Jakarta Sans', 'normal');
        $canvas->page_text(34, $canvas->get_height() - 27, __('Monthly uptime report').' · '.$report['month'].' · UTC', $font, 8, [0.40, 0.44, 0.52]);
        $canvas->page_text($canvas->get_width() - 72, $canvas->get_height() - 27, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.40, 0.44, 0.52]);

        return (string) $pdf->output();
    }
}
