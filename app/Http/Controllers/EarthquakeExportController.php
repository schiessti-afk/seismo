<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExportEarthquakes;
use App\Http\Requests\EarthquakeExportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EarthquakeExportController extends Controller
{
    public function csv(EarthquakeExportRequest $request, ExportEarthquakes $export): StreamedResponse
    {
        $result = $export($request->filters());
        $filename = 'seismo-export-'.now()->utc()->format('Ymd\THis\Z').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        if ($result['truncated']) {
            $headers['X-Seismo-Export-Truncated'] = '1';
        }

        return response()->stream(function () use ($export, $result): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $export->csvHeaders());

            foreach ($result['rows'] as $earthquake) {
                fputcsv($handle, $export->csvRow($earthquake));
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function geojson(EarthquakeExportRequest $request, ExportEarthquakes $export): JsonResponse
    {
        $result = $export($request->filters());
        $filename = 'seismo-export-'.now()->utc()->format('Ymd\THis\Z').'.geojson';

        $response = response()->json(
            $export->geoJsonFeatureCollection($result['rows']),
            200,
            [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
            JSON_UNESCAPED_SLASHES,
        );

        if ($result['truncated']) {
            $response->headers->set('X-Seismo-Export-Truncated', '1');
        }

        return $response;
    }
}
