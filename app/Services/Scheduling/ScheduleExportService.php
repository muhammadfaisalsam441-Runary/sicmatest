<?php

namespace App\Services\Scheduling;

use App\Enums\SessionMode;
use App\Models\Academic\ClassGroup;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Support\Wita;
use Dompdf\Cpdf;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ScheduleExportService
{
    public const array GROUP_LABELS = ['kelas' => 'Kelas', 'dosen' => 'Dosen', 'ruang' => 'Ruang'];

    public const string DEPARTMENT_TITLE = 'JURUSAN TEKNIK INFORMATIKA DAN KOMPUTER';

    /** @var list<string> */
    public const array HEADERS = [
        'NO',
        'KELAS',
        'HARI',
        'JAM',
        'WITA',
        'RUANG',
        'KODE MK',
        'MATA KULIAH',
        'SKS',
        'DOSEN PENGAMPU',
        'DOSEN MITRA',
        'DOSEN MITRA 2',
    ];

    /** @var list<array{label: string, start_minute: int, end_minute: int}> */
    public const array DEFAULT_SLOTS = [
        ['label' => 'I', 'start_minute' => 450, 'end_minute' => 500],
        ['label' => 'II', 'start_minute' => 500, 'end_minute' => 550],
        ['label' => 'III', 'start_minute' => 555, 'end_minute' => 605],
        ['label' => 'IV', 'start_minute' => 605, 'end_minute' => 655],
        ['label' => 'V', 'start_minute' => 660, 'end_minute' => 710],
        ['label' => 'VI', 'start_minute' => 710, 'end_minute' => 760],
        ['label' => 'VII', 'start_minute' => 790, 'end_minute' => 840],
        ['label' => 'VIII', 'start_minute' => 840, 'end_minute' => 890],
        ['label' => 'IX', 'start_minute' => 890, 'end_minute' => 940],
        ['label' => 'X', 'start_minute' => 940, 'end_minute' => 995],
        ['label' => 'XI', 'start_minute' => 995, 'end_minute' => 1050],
    ];

    public function documentTitle(ScheduleVersion $version): string
    {
        $periodName = trim((string) ($version->period?->name ?? ''));

        if (preg_match('/^(Ganjil|Genap|Antara)\s+(\d{4}\s*\/\s*\d{4})$/i', $periodName, $matches) === 1) {
            $term = Str::upper($matches[1]);
            $year = preg_replace('/\s+/', '', $matches[2]);

            return "JADWAL PERKULIAHAN SEMESTER {$term} TAHUN AKADEMIK {$year}";
        }

        return 'JADWAL PERKULIAHAN '.Str::upper($periodName);
    }

    /**
     * Susun baris ekspor datar bernomor urut sesuai format resmi JTIK.
     *
     * @param  Collection<string, Collection<int, ScheduleSession>>  $groups
     * @return list<array{
     *     no: int,
     *     kelas: string,
     *     hari: string,
     *     jam: string,
     *     wita: string,
     *     ruang: string,
     *     kode_mk: string,
     *     mata_kuliah: string,
     *     sks: string,
     *     dosen_pengampu: string,
     *     dosen_mitra: string,
     *     dosen_mitra_2: string,
     *     shaded: bool
     * }>
     */
    public function flatRows(ScheduleVersion $version, string $view, Collection $groups): array
    {
        $periodSlots = $version->period?->relationLoaded('timeSlots')
            ? $version->period->timeSlots
            : ($version->period?->timeSlots()->orderBy('start_minute')->get() ?? collect());

        $timeSlots = $periodSlots->isNotEmpty()
            ? $periodSlots->sortBy('start_minute')->values()
            : collect(self::DEFAULT_SLOTS);

        $orderedGroups = $this->sortGroups($view, $groups);
        $rows = [];
        $no = 1;
        $classBlockIndex = -1;
        $previousKelas = null;

        foreach ($orderedGroups as $groupName => $sessions) {
            $sortedSessions = $sessions
                ->sort(fn (ScheduleSession $a, ScheduleSession $b): int => [
                    $a->day_of_week === null ? 1 : 0,
                    $a->day_of_week ?? 99,
                    $a->start_minute ?? 9999,
                    $a->end_minute ?? 9999,
                    $a->id,
                ] <=> [
                    $b->day_of_week === null ? 1 : 0,
                    $b->day_of_week ?? 99,
                    $b->start_minute ?? 9999,
                    $b->end_minute ?? 9999,
                    $b->id,
                ])
                ->values();

            foreach ($sortedSessions as $session) {
                [$lead, $mitra1, $mitra2] = $this->splitLecturers($session);

                $course = $session->courseOffering?->course;
                $raw = is_array($session->source_raw) ? $session->source_raw : [];
                $kodeMk = trim((string) ($course?->code ?? $raw['course_code'] ?? $raw['kode_mk'] ?? ''));
                $sksValue = $course?->sks ?? $raw['sks'] ?? null;
                $kelas = $view === 'kelas' && $groupName !== 'Tanpa kelas'
                    ? $groupName
                    : ($session->participants->pluck('name')->implode(' + ') ?: '—');

                if ($kelas !== $previousKelas) {
                    $classBlockIndex++;
                    $previousKelas = $kelas;
                }

                $rows[] = [
                    'no' => $no++,
                    'kelas' => $kelas,
                    'hari' => $session->day_of_week === null ? 'Belum terjadwal' : Wita::dayName($session->day_of_week),
                    'jam' => $this->formatRomanSlots($timeSlots, $session->start_minute, $session->end_minute),
                    'wita' => $this->formatWitaRange($session->start_minute, $session->end_minute),
                    'ruang' => match ($session->mode) {
                        SessionMode::Online => 'Online',
                        SessionMode::Unscheduled => 'Belum terjadwal',
                        SessionMode::Offline => $session->room?->name ?? 'Tanpa ruang',
                    },
                    'kode_mk' => $kodeMk,
                    'mata_kuliah' => $session->title,
                    'sks' => ($sksValue !== null && $sksValue !== '') ? (string) $sksValue : '',
                    'dosen_pengampu' => $lead,
                    'dosen_mitra' => $mitra1,
                    'dosen_mitra_2' => $mitra2,
                    'shaded' => ($classBlockIndex % 2) === 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{
     *     no: int,
     *     kelas: string,
     *     hari: string,
     *     jam: string,
     *     wita: string,
     *     ruang: string,
     *     kode_mk: string,
     *     mata_kuliah: string,
     *     sks: string,
     *     dosen_pengampu: string,
     *     dosen_mitra: string,
     *     dosen_mitra_2: string,
     *     shaded?: bool
     * }>  $rows
     */
    public function writeExcel(ScheduleVersion $version, string $view, array $rows): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jadwal per '.strtolower(self::GROUP_LABELS[$view]));
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(9.5)->getColor()->setRGB('000000');
        $book->getProperties()->setCreator('SICMA')->setTitle("Jadwal {$version->period->name} v{$version->version_no}");
        $sheet->setShowGridlines(true);

        foreach ([
            'A' => 5,
            'B' => 18,
            'C' => 10,
            'D' => 9,
            'E' => 15,
            'F' => 17,
            'G' => 16,
            'H' => 34,
            'I' => 6,
            'J' => 34,
            'K' => 32,
            'L' => 24,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->mergeCells('A1:L1')->setCellValueExplicit('A1', $this->documentTitle($version), DataType::TYPE_STRING);
        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $sheet->mergeCells('A2:L2')->setCellValueExplicit('A2', self::DEPARTMENT_TITLE, DataType::TYPE_STRING);
        $sheet->getStyle('A2:L2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);

        if ($view !== 'kelas') {
            $sheet->mergeCells('A3:L3')->setCellValueExplicit('A3', 'Tinjauan Per '.self::GROUP_LABELS[$view], DataType::TYPE_STRING);
            $sheet->getStyle('A3:L3')->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '465C73']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension(3)->setRowHeight(16);
        } else {
            $sheet->getRowDimension(3)->setRowHeight(10);
        }

        $sheet->fromArray(self::HEADERS, null, 'A4');
        $sheet->getStyle('A4:L4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A9C4E2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(22);

        $index = 5;
        foreach ($rows as $row) {
            $values = [
                (string) $row['no'],
                $row['kelas'],
                $row['hari'],
                $row['jam'],
                $row['wita'],
                $row['ruang'],
                $row['kode_mk'],
                $row['mata_kuliah'],
                $row['sks'],
                $row['dosen_pengampu'],
                $row['dosen_mitra'],
                $row['dosen_mitra_2'],
            ];

            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index], (string) $value, DataType::TYPE_STRING);
            }

            if ($row['shaded'] ?? false) {
                $sheet->getStyle("A{$index}:L{$index}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF5D6');
            }

            $index++;
        }

        if ($index === 5) {
            $sheet->mergeCells('A5:L5')->setCellValue('A5', 'Belum ada sesi pada versi ini.');
            $sheet->getStyle('A5:L5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $lastRow = 5;
        } else {
            $lastRow = $index - 1;
            $sheet->setAutoFilter("A4:L{$lastRow}");
            $sheet->getStyle("A5:L{$lastRow}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I'] as $centerCol) {
                $sheet->getStyle("{$centerCol}5:{$centerCol}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            foreach (['H', 'J', 'K', 'L'] as $leftCol) {
                $sheet->getStyle("{$leftCol}5:{$leftCol}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
        }

        $sheet->getStyle("A4:L{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('000000');

        $sheet->freezePane('A5');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(4, 4)
            ->setPrintArea("A1:L{$lastRow}");
        $sheet->getHeaderFooter()->setOddFooter('&LSICMA&RHalaman &P / &N');

        try {
            new Xlsx($book)->save('php://output');
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function subtitle(ScheduleVersion $version, string $view, int $sessionCount): string
    {
        return "{$version->status->label()} v{$version->version_no}  •  Per ".strtolower(self::GROUP_LABELS[$view])
            ."  •  {$sessionCount} sesi  •  Tanggal mulai ".Wita::date($version->effective_from);
    }

    public function filename(ScheduleVersion $version, string $view, string $extension): string
    {
        $period = Str::slug(str_replace('/', '-', $version->period->name));

        return "sicma-{$period}-v{$version->version_no}-per-{$view}.{$extension}";
    }

    public function pdf(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $document = new Dompdf($options);
        $document->setPaper('A4', 'landscape');
        $document->loadHtml($html, 'UTF-8');
        $document->render();

        $canvas = $document->getCanvas();
        $font = $document->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 118, $canvas->get_height() - 27, 'Halaman {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.27, 0.36, 0.45]);

        return $document->output();
    }

    /**
     * Render PDF jadwal 12 kolom secara langsung memakai Dompdf\Cpdf agar hemat memori dan jauh lebih cepat.
     *
     * @param  list<array{
     *     no: int,
     *     kelas: string,
     *     hari: string,
     *     jam: string,
     *     wita: string,
     *     ruang: string,
     *     kode_mk: string,
     *     mata_kuliah: string,
     *     sks: string,
     *     dosen_pengampu: string,
     *     dosen_mitra: string,
     *     dosen_mitra_2: string
     * }>  $rows
     */
    public function renderTablePdf(ScheduleVersion $version, string $view, array $rows): string
    {
        $pageWidth = 841.89;
        $pageHeight = 595.28;
        $marginX = 24.0;
        $marginTop = 24.0;
        $marginBottom = 28.0;

        $columns = [
            ['key' => 'no', 'label' => 'NO', 'width' => 24.0, 'align' => 'C'],
            ['key' => 'kelas', 'label' => 'KELAS', 'width' => 78.0, 'align' => 'C'],
            ['key' => 'hari', 'label' => 'HARI', 'width' => 42.0, 'align' => 'C'],
            ['key' => 'jam', 'label' => 'JAM', 'width' => 42.0, 'align' => 'C'],
            ['key' => 'wita', 'label' => 'WITA', 'width' => 66.0, 'align' => 'C'],
            ['key' => 'ruang', 'label' => 'RUANG', 'width' => 68.0, 'align' => 'C'],
            ['key' => 'kode_mk', 'label' => 'KODE MK', 'width' => 74.0, 'align' => 'C'],
            ['key' => 'mata_kuliah', 'label' => 'MATA KULIAH', 'width' => 122.0, 'align' => 'L'],
            ['key' => 'sks', 'label' => 'SKS', 'width' => 26.0, 'align' => 'C'],
            ['key' => 'dosen_pengampu', 'label' => 'DOSEN PENGAMPU', 'width' => 102.0, 'align' => 'L'],
            ['key' => 'dosen_mitra', 'label' => 'DOSEN MITRA', 'width' => 96.0, 'align' => 'L'],
            ['key' => 'dosen_mitra_2', 'label' => 'DOSEN MITRA 2', 'width' => 53.89, 'align' => 'L'],
        ];

        $tableWidth = array_sum(array_column($columns, 'width'));
        $fontSize = 6.6;
        $lineHeight = 8.2;
        $padX = 3.0;
        $padY = 2.4;
        $minRowHeight = 12.2;
        $headerFontSize = 6.8;
        $headerLineHeight = 8.4;
        $headerHeight = 18.0;

        $cpdf = new Cpdf([0, 0, $pageWidth, $pageHeight], false);

        // Pre-wrap header labels dengan Helvetica-Bold agar lebar terukur akurat.
        $cpdf->selectFont('Helvetica-Bold');
        $wrappedHeaders = [];
        $maxHeaderLines = 1;
        foreach ($columns as $colIndex => $column) {
            $lines = $this->wrapPdfText($cpdf, $column['label'], $headerFontSize, $column['width'] - ($padX * 2));
            $wrappedHeaders[$colIndex] = $lines;
            $maxHeaderLines = max($maxHeaderLines, count($lines));
        }
        $headerHeight = max($headerHeight, ($maxHeaderLines * $headerLineHeight) + ($padY * 2));

        // Pre-wrap seluruh sel isi dengan Helvetica untuk paginasi cepat sebelum menggambar.
        $cpdf->selectFont('Helvetica');
        $preparedRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $colIndex => $column) {
                $rawText = (string) ($row[$column['key']] ?? '');
                $lines = $this->wrapPdfText($cpdf, $rawText, $fontSize, $column['width'] - ($padX * 2));
                $cells[$colIndex] = $lines;
                $maxLines = max($maxLines, count($lines));
            }

            $preparedRows[] = [
                'index' => $rowIndex,
                'shaded' => (bool) ($row['shaded'] ?? false),
                'cells' => $cells,
                'height' => max($minRowHeight, ($maxLines * $lineHeight) + ($padY * 2)),
            ];
        }

        $titleBlockHeight = $view !== 'kelas' ? 48.0 : 36.0;
        $pages = [];
        $currentPageRows = [];
        $cursorY = $pageHeight - $marginTop - $titleBlockHeight - $headerHeight;

        if ($preparedRows === []) {
            $pages[] = [];
        } else {
            foreach ($preparedRows as $preparedRow) {
                if ($currentPageRows !== [] && ($cursorY - $preparedRow['height']) < $marginBottom) {
                    $pages[] = $currentPageRows;
                    $currentPageRows = [];
                    $cursorY = $pageHeight - $marginTop - $headerHeight;
                }

                $currentPageRows[] = $preparedRow;
                $cursorY -= $preparedRow['height'];
            }

            if ($currentPageRows !== []) {
                $pages[] = $currentPageRows;
            }
        }

        $totalPages = count($pages);
        $titleText = $this->encodePdfText($this->documentTitle($version));
        $departmentText = $this->encodePdfText(self::DEPARTMENT_TITLE);
        $subtitleText = $view !== 'kelas' ? $this->encodePdfText('Tinjauan Per '.self::GROUP_LABELS[$view]) : null;
        $emptyText = $this->encodePdfText('Belum ada sesi pada versi ini.');

        foreach ($pages as $pageIndex => $pageRows) {
            if ($pageIndex > 0) {
                $cpdf->newPage();
            }

            $topY = $pageHeight - $marginTop;

            if ($pageIndex === 0) {
                $cpdf->selectFont('Helvetica-Bold');
                $cpdf->setColor([0, 0, 0]);

                $titleWidth = $cpdf->getTextWidth(12.5, $titleText);
                $cpdf->addText(($pageWidth - $titleWidth) / 2, $topY - 12.0, 12.5, $titleText);

                $deptWidth = $cpdf->getTextWidth(11.5, $departmentText);
                $cpdf->addText(($pageWidth - $deptWidth) / 2, $topY - 26.5, 11.5, $departmentText);

                if ($subtitleText !== null) {
                    $cpdf->selectFont('Helvetica-Oblique');
                    $cpdf->setColor([0.27, 0.36, 0.45]);
                    $subWidth = $cpdf->getTextWidth(8.5, $subtitleText);
                    $cpdf->addText(($pageWidth - $subWidth) / 2, $topY - 39.0, 8.5, $subtitleText);
                }

                $topY -= $titleBlockHeight;
            }

            // Header tabel 12 kolom di setiap halaman.
            $headerBottomY = $topY - $headerHeight;
            $cpdf->setLineStyle(0.6);
            $cpdf->setStrokeColor([0, 0, 0]);
            $cpdf->setColor([169 / 255, 196 / 255, 226 / 255]);
            $cpdf->filledRectangle($marginX, $headerBottomY, $tableWidth, $headerHeight);

            $cpdf->selectFont('Helvetica-Bold');
            $cpdf->setColor([0, 0, 0]);
            $cellX = $marginX;
            foreach ($columns as $colIndex => $column) {
                $cpdf->rectangle($cellX, $headerBottomY, $column['width'], $headerHeight);
                $lines = $wrappedHeaders[$colIndex];
                $blockTop = $headerBottomY + (($headerHeight + (count($lines) * $headerLineHeight)) / 2);

                foreach ($lines as $lineIdx => $line) {
                    $lineTop = $blockTop - ($lineIdx * $headerLineHeight);
                    $baseline = $lineTop - (($headerLineHeight + ($headerFontSize * 0.72)) / 2);
                    $textWidth = $cpdf->getTextWidth($headerFontSize, $line);
                    $textX = $cellX + max($padX, ($column['width'] - $textWidth) / 2);
                    $cpdf->addText($textX, $baseline, $headerFontSize, $line);
                }

                $cellX += $column['width'];
            }

            $currentBottomY = $headerBottomY;
            $cpdf->setLineStyle(0.5);

            if ($pageRows === []) {
                $rowBottomY = $currentBottomY - 18.0;
                $cpdf->setStrokeColor([0, 0, 0]);
                $cpdf->rectangle($marginX, $rowBottomY, $tableWidth, 18.0);
                $cpdf->selectFont('Helvetica');
                $cpdf->setColor([0, 0, 0]);
                $emptyWidth = $cpdf->getTextWidth($fontSize, $emptyText);
                $cpdf->addText(($pageWidth - $emptyWidth) / 2, $rowBottomY + 6.5, $fontSize, $emptyText);
            } else {
                foreach ($pageRows as $preparedRow) {
                    $rowHeight = $preparedRow['height'];
                    $rowBottomY = $currentBottomY - $rowHeight;

                    if ($preparedRow['shaded']) {
                        $cpdf->setColor([1.0, 245 / 255, 214 / 255]);
                        $cpdf->filledRectangle($marginX, $rowBottomY, $tableWidth, $rowHeight);
                    }

                    $cpdf->selectFont('Helvetica');
                    $cpdf->setColor([0, 0, 0]);
                    $cpdf->setStrokeColor([0, 0, 0]);

                    $cellX = $marginX;
                    foreach ($columns as $colIndex => $column) {
                        $cpdf->rectangle($cellX, $rowBottomY, $column['width'], $rowHeight);
                        $lines = $preparedRow['cells'][$colIndex];
                        $blockTop = $rowBottomY + (($rowHeight + (count($lines) * $lineHeight)) / 2);

                        foreach ($lines as $lineIdx => $line) {
                            if ($line === '') {
                                continue;
                            }
                            $lineTop = $blockTop - ($lineIdx * $lineHeight);
                            $baseline = $lineTop - (($lineHeight + ($fontSize * 0.72)) / 2);

                            if ($column['align'] === 'C') {
                                $textWidth = $cpdf->getTextWidth($fontSize, $line);
                                $textX = $cellX + max($padX, ($column['width'] - $textWidth) / 2);
                            } else {
                                $textX = $cellX + $padX;
                            }

                            $cpdf->addText($textX, $baseline, $fontSize, $line);
                        }

                        $cellX += $column['width'];
                    }

                    $currentBottomY = $rowBottomY;
                }
            }

            $cpdf->selectFont('Helvetica');
            $cpdf->setColor([0.27, 0.36, 0.45]);
            $footerText = $this->encodePdfText('Halaman '.($pageIndex + 1).' / '.$totalPages);
            $footerWidth = $cpdf->getTextWidth(7.5, $footerText);
            $cpdf->addText($pageWidth - $marginX - $footerWidth, 14.0, 7.5, $footerText);
        }

        return $cpdf->output();
    }

    private function encodePdfText(string $text): string
    {
        $normalized = str_replace(['—', '–'], ['-', '-'], $text);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $normalized);

        return $encoded !== false ? $encoded : $normalized;
    }

    /**
     * Pecah teks sel menjadi beberapa baris agar tidak melewati lebar kolom.
     *
     * @return list<string>
     */
    private function wrapPdfText(Cpdf $cpdf, string $text, float $fontSize, float $maxWidth): array
    {
        $encoded = trim($this->encodePdfText($text));
        if ($encoded === '') {
            return [''];
        }

        $safeMax = max(10.0, $maxWidth);
        $paragraphs = preg_split('/\R+/', $encoded) ?: [$encoded];
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            if ($words === []) {
                continue;
            }

            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                if ($cpdf->getTextWidth($fontSize, $candidate) <= $safeMax) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }

                if ($cpdf->getTextWidth($fontSize, $word) <= $safeMax) {
                    $current = $word;

                    continue;
                }

                // Pecah kata/kode yang sangat panjang karakter demi karakter.
                $chunk = '';
                $len = strlen($word);
                for ($i = 0; $i < $len; $i++) {
                    $char = $word[$i];
                    if ($chunk !== '' && $cpdf->getTextWidth($fontSize, $chunk.$char) > $safeMax) {
                        $lines[] = $chunk;
                        $chunk = $char;
                    } else {
                        $chunk .= $char;
                    }
                }
                $current = $chunk;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines !== [] ? $lines : [''];
    }

    /**
     * @param  Collection<string, Collection<int, ScheduleSession>>  $groups
     * @return Collection<string, Collection<int, ScheduleSession>>
     */
    private function sortGroups(string $view, Collection $groups): Collection
    {
        if ($view === 'kelas') {
            $entries = $groups->map(function (Collection $sessions, string $groupName): array {
                $classGroup = $sessions
                    ->flatMap(fn (ScheduleSession $session): Collection => $session->participants)
                    ->firstWhere('name', $groupName);

                return [
                    'name' => $groupName,
                    'sessions' => $sessions,
                    'tuple' => $this->classGroupSortTuple($groupName, $classGroup instanceof ClassGroup ? $classGroup : null),
                ];
            })->values()->all();

            usort($entries, function (array $a, array $b): int {
                $cmp = $a['tuple'] <=> $b['tuple'];

                return $cmp !== 0 ? $cmp : strnatcasecmp($a['name'], $b['name']);
            });

            return collect($entries)->mapWithKeys(fn (array $entry): array => [$entry['name'] => $entry['sessions']]);
        }

        if ($view === 'ruang') {
            $keys = $groups->keys()->all();
            usort($keys, function (string $a, string $b): int {
                $rank = fn (string $name): int => match ($name) {
                    'Online' => 1,
                    'Tanpa ruang' => 2,
                    'Belum terjadwal' => 3,
                    default => 0,
                };
                $cmp = $rank($a) <=> $rank($b);

                return $cmp !== 0 ? $cmp : strnatcasecmp($a, $b);
            });

            return collect($keys)->mapWithKeys(fn (string $key): array => [$key => $groups[$key]]);
        }

        $keys = $groups->keys()->all();
        usort($keys, function (string $a, string $b): int {
            $cmp = ($a === 'Tanpa dosen' ? 1 : 0) <=> ($b === 'Tanpa dosen' ? 1 : 0);

            return $cmp !== 0 ? $cmp : strnatcasecmp($a, $b);
        });

        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => $groups[$key]]);
    }

    /**
     * Urutkan kelas sesuai standar JTIK: angkatan terbaru ke terlama, PTIK sebelum TEKOM, reguler/pilihan sebelum ULANG.
     *
     * @return array{int, int, int, int}
     */
    private function classGroupSortTuple(string $groupName, ?ClassGroup $classGroup): array
    {
        if ($groupName === 'Tanpa kelas') {
            return [1, PHP_INT_MAX, 9, 9];
        }

        $year = $classGroup?->cohort_year;
        if ($year === null && preg_match('/\b(20\d{2})\b/', $groupName, $match) === 1) {
            $year = (int) $match[1];
        }

        $prodiRaw = Str::upper(trim((string) ($classGroup?->study_program ?? '')));
        $nameUpper = Str::upper(trim($groupName));

        $prodiRank = match (true) {
            in_array($prodiRaw, ['PTIK', 'PENDIDIKAN TEKNIK INFORMATIKA DAN KOMPUTER'], true)
                || str_starts_with($nameUpper, 'PTIK') => 0,
            in_array($prodiRaw, ['TEKOM', 'TEKNIK KOMPUTER'], true)
                || str_starts_with($nameUpper, 'TEKOM') => 1,
            default => 2,
        };

        $ulangRank = str_contains($nameUpper, 'ULANG') ? 1 : 0;

        return [0, $year !== null ? -$year : PHP_INT_MAX, $prodiRank, $ulangRank];
    }

    /**
     * Format rentang slot jam dalam angka Romawi (mis. III-IV).
     *
     * @param  Collection<int, mixed>  $timeSlots
     */
    private function formatRomanSlots(Collection $timeSlots, ?int $startMinute, ?int $endMinute): string
    {
        if ($startMinute === null || $endMinute === null) {
            return '—';
        }

        $overlapping = $timeSlots->filter(function (mixed $slot) use ($startMinute, $endMinute): bool {
            $slotStart = is_array($slot) ? (int) $slot['start_minute'] : (int) $slot->start_minute;
            $slotEnd = is_array($slot) ? (int) $slot['end_minute'] : (int) $slot->end_minute;

            return $slotStart < $endMinute && $slotEnd > $startMinute;
        })->values();

        if ($overlapping->isEmpty()) {
            return '—';
        }

        $first = $overlapping->first();
        $last = $overlapping->last();
        $firstLabel = is_array($first) ? (string) $first['label'] : (string) $first->label;
        $lastLabel = is_array($last) ? (string) $last['label'] : (string) $last->label;

        return $firstLabel === $lastLabel ? $firstLabel : "{$firstLabel}-{$lastLabel}";
    }

    private function formatWitaRange(?int $startMinute, ?int $endMinute): string
    {
        if ($startMinute === null || $endMinute === null) {
            return '—';
        }

        return sprintf(
            '%02d.%02d - %02d.%02d',
            intdiv($startMinute, 60),
            $startMinute % 60,
            intdiv($endMinute, 60),
            $endMinute % 60,
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function splitLecturers(ScheduleSession $session): array
    {
        $leads = $session->lecturers
            ->filter(fn ($lecturer): bool => ($lecturer->pivot->role ?? '') === 'lead')
            ->values();
        $partners = $session->lecturers
            ->filter(fn ($lecturer): bool => ($lecturer->pivot->role ?? '') !== 'lead')
            ->values();

        if ($leads->isEmpty() && $partners->isNotEmpty()) {
            $leads->push($partners->shift());
        }

        return [
            $leads->pluck('name')->implode(', '),
            (string) ($partners->get(0)?->name ?? ''),
            $partners->slice(1)->pluck('name')->implode(', '),
        ];
    }
}
