<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Helper streaming unduhan CSV dengan UTF-8 BOM agar Excel membaca karakter dengan benar.
 */
final class CsvStream
{
    /**
     * Stream respons unduhan file CSV.
     *
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows, string $delimiter = ','): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $delimiter): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            if ($headers !== []) {
                fputcsv($handle, $headers, $delimiter);
            }

            foreach ($rows as $row) {
                fputcsv($handle, $row, $delimiter);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
