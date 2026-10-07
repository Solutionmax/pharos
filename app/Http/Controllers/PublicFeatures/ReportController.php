<?php

namespace App\Http\Controllers\PublicFeatures;

use App\Http\Controllers\Controller;
use App\Services\MonthlyUptimeReport;
use App\Support\Csv;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function show(Request $request, MonthlyUptimeReport $reports)
    {
        $admin = $request->is('admin/*');
        $report = $reports->build($reports->month($request), ! $admin);
        $format = str_ends_with($request->path(), '.csv') ? 'csv' : (str_ends_with($request->path(), '.pdf') ? 'pdf' : 'html');
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, ['Service', 'Up seconds', 'Down seconds', 'Uptime %', 'Coverage %', 'Excluded maintenance seconds', 'Unobserved seconds', 'Month', 'Timezone'], ',', '"', '');
            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_map(Csv::cell(...), [$row['name'], $row['up_seconds'], $row['down_seconds'], $row['uptime'] === null ? '' : number_format($row['uptime'], 2, '.', ''), $row['coverage'], $row['excluded_seconds'], $row['unobserved_seconds'], $report['month'], 'UTC']), ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="uptime-'.$report['month'].'.csv"')->header('Cache-Control', 'no-store');
        }
        if ($format === 'pdf') {
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => storage_path('app'), 'tempDir' => storage_path('app'), 'fontCache' => storage_path('app')]);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('public.report-pdf', compact('report'))->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output())->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="uptime-'.$report['month'].'.pdf"')->header('Cache-Control', 'no-store');
        }

        return view($admin ? 'admin.reports' : 'public.report', compact('report', 'admin'));
    }
}
