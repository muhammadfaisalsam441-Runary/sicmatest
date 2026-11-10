<?php

namespace App\Services\Scheduling;

use App\Enums\SessionMode;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Support\Wita;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ScheduleExportService
{
    public const array GROUP_LABELS = ['kelas' => 'Kelas', 'dosen' => 'Dosen', 'ruang' => 'Ruang'];

    /**
     * @param  Collection<string, Collection<int, ScheduleSession>>  $groups
     * @return Collection<string, Collection<int, list<string>>>
     */
    public function rows(Collection $groups): Collection
    {
        return $groups->map(fn (Collection $sessions): Collection => $sessions->map(fn (ScheduleSession $session): array => [
            $session->day_of_week === null ? 'Belum terjadwal' : Wita::dayName($session->day_of_week),
            $session->start_minute === null || $session->end_minute === null
                ? '—'
                : Wita::minutes($session->start_minute).'–'.Wita::minutes($session->end_minute),
            $session->title,
            $session->participants->pluck('name')->implode(' + ') ?: '—',
            match ($session->mode) {
                SessionMode::Online => 'Online',
                SessionMode::Unscheduled => '—',
                SessionMode::Offline => $session->room?->name ?? 'Tanpa ruang',
            },
            $session->lecturers->map(fn ($lecturer): string => ($lecturer->pivot->role === 'lead' ? 'Pengampu: ' : 'Mitra: ').$lecturer->name)->implode("\n") ?: '—',
            $session->phase->label(),
        ]));
    }

    /**
     * @param  Collection<string, Collection<int, list<string>>>  $groups
     */
    public function writeExcel(ScheduleVersion $version, string $view, int $sessionCount, Collection $groups): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jadwal per '.strtolower(self::GROUP_LABELS[$view]));
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11)->getColor()->setRGB('152B43');
        $book->getProperties()->setCreator('SICMA')->setTitle("Jadwal {$version->period->name} v{$version->version_no}");
        $sheet->setShowGridlines(false);

        foreach (['A' => 23, 'B' => 18, 'C' => 18, 'D' => 36, 'E' => 26, 'F' => 19, 'G' => 55, 'H' => 19] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->mergeCells('A1:H1')->setCellValueExplicit('A1', "SICMA  |  Jadwal Perkuliahan  ·  {$version->period->name}", DataType::TYPE_STRING);
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '17395D']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->mergeCells('A2:H2')->setCellValueExplicit('A2', $this->subtitle($version, $view, $sessionCount), DataType::TYPE_STRING);
        $sheet->getStyle('A2:H2')->getFont()->getColor()->setRGB('465C73');
        $sheet->getRowDimension(2)->setRowHeight(22);

        $sheet->fromArray(['Kelompok '.self::GROUP_LABELS[$view], 'Hari', 'Jam (WITA)', 'Mata Kuliah', 'Kelas Peserta', 'Ruang', 'Tim Pengajar', 'Fase'], null, 'A4');
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '245987']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(23);

        $index = 5;
        foreach ($groups as $group => $rows) {
            foreach ($rows as $row) {
                foreach ([$group, ...$row] as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $index], $value, DataType::TYPE_STRING);
                }
                if ($index % 2 === 0) {
                    $sheet->getStyle("A{$index}:H{$index}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDF4FA');
                }
                $index++;
            }
        }

        if ($index === 5) {
            $sheet->mergeCells('A5:H5')->setCellValue('A5', 'Belum ada sesi pada versi ini.');
            $index++;
        } else {
            $sheet->setAutoFilter('A4:H'.($index - 1));
        }

        $sheet->freezePane('B5');
        $sheet->getStyle('A5:H'.($index - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 4)
            ->setPrintArea('A1:H'.($index - 1));
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
}
