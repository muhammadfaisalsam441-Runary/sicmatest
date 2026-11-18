<?php

namespace App\Services\Scheduling;

use App\Models\Academic\ClassGroup;
use App\Models\Room\Room;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Pembuat templat jadwal perkuliahan (baris contoh CSV dan lembar kerja Excel .xlsx beserta sheet referensi).
 */
class ScheduleImportTemplateBuilder
{
    /**
     * Isi baris templat jadwal lengkap (header + contoh).
     *
     * @return list<list<string|int>>
     */
    public function templateRows(): array
    {
        return [
            ['Kategori', 'Kelas', 'Hari', 'Jam', 'WITA', 'Ruang', 'Kode MK', 'Mata Kuliah', 'SKS', 'Dosen Pengampu', 'Dosen Mitra', 'Dosen Mitra 2', 'Periode'],
            ['Angkatan 2026', 'Tekom C', 'Senin', 'I - II', '07.30 - 09.10', 'AE 107', 'TKM2301', 'Pemrograman Web', 2, 'Rina Kartika, S.Kom., M.Kom.', '', '', 'Satu semester'],
            ['Angkatan 2026', 'PTIK A', 'Selasa', 'I - III', '07.30 - 10.05', 'Online', 'TKM2306', 'Kecerdasan Buatan', 3, 'Yusuf Habibi, S.T., M.T.', 'Sinta Amelia, S.T., M.T.', '', 'Satu semester'],
            ['Angkatan 2025', 'PTIK B', '', '', 'Hubungi Dosen', '', 'TKM2305', 'Sistem Operasi', 2, 'Bpk. Arman', '', '', 'Satu semester'],
            ['Angkatan 2024', 'PTIK C', 'Rabu', 'V - VI', '11.00 - 12.40', 'AE 105', 'TKM2310', 'Kapita Selekta', 2, 'Dr. Iwan Suhardi, ST., MT.', '', '', 'Sebelum UTS'],
        ];
    }

    /**
     * Buat lembar kerja templat Excel (.xlsx) rapi dengan format resmi JTIK dan sheet referensi.
     */
    public function templateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $templateRows = $this->templateRows();
        $headers = $templateRows[0];
        $rows = array_slice($templateRows, 1);

        // ----------------------------------------------------
        // Sheet 1: Jadwal Perkuliahan
        // ----------------------------------------------------
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Jadwal Perkuliahan');
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        // Header Styling
        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data Rows Alignment & Border
        $lastRow = count($rows) + 1;
        $sheet->getStyle("A1:M{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Column Alignment
        $sheet->getStyle("A2:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C2:E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F2:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("G2:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I2:I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("M2:M{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->setAutoFilter("A1:M{$lastRow}");
        $sheet->freezePane('A2');

        // ----------------------------------------------------
        // Sheet 2: Panduan & Referensi
        // ----------------------------------------------------
        $refSheet = $spreadsheet->createSheet();
        $refSheet->setTitle('Panduan & Referensi');

        $refSheet->setCellValue('A1', 'PANDUAN & DAFTAR REFERENSI IMPOR JADWAL SICMA');
        $refSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1E3A8A');

        $guideHeader = ['Nama Kolom', 'Wajib / Opsional', 'Format Nilai & Contoh', 'Keterangan'];
        $refSheet->fromArray([$guideHeader], null, 'A3');
        $refSheet->getStyle('A3:D3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $guideRows = [
            ['Kategori', 'Opsional', 'Angkatan 2026, Angkatan 2025', 'Tingkat/tahun angkatan mahasiswa'],
            ['Kelas', 'Wajib', 'PTIK A, Tekom C', 'Harus sesuai dengan nama atau kode kelas di Data Akademik'],
            ['Hari', 'Wajib (bila ada jadwal)', 'Senin, Selasa, Rabu, Kamis, Jumat', 'Perkuliahan hanya Senin–Jumat. Kosongkan jika belum terjadwal'],
            ['Jam', 'Opsional', 'I - II, III - IV, VII - VIII', 'Nomor slot jam perkuliahan dalam angka romawi'],
            ['WITA', 'Wajib', '07.30 - 09.10 atau Hubungi Dosen', 'Gunakan format jam mulai - selesai, atau "Hubungi Dosen" untuk sesi belum terjadwal'],
            ['Ruang', 'Wajib (bila tatap muka)', 'AE 107, Lab Jaringan, Online', 'Nama ruang sesuai master SICMA, atau "Online" untuk perkuliahan daring'],
            ['Kode MK', 'Wajib', '25B09CKP02109, TKM2301', 'Kode mata kuliah resmi'],
            ['Mata Kuliah', 'Wajib', 'Pemrograman Web, Matematika Dasar', 'Nama mata kuliah lengkap'],
            ['SKS', 'Wajib', '2, 3, 4', 'Bobot SKS (1 SKS = 50 menit)'],
            ['Dosen Pengampu', 'Wajib', 'Dr. Iwan Suhardi, ST., MT.', 'Dosen utama / koordinator mata kuliah'],
            ['Dosen Mitra', 'Opsional', 'Wirawan Setialaksana, S.Pd., M.Sc.', 'Dosen kedua (team teaching) bila ada'],
            ['Dosen Mitra 2', 'Opsional', 'Nama Dosen, Gelar', 'Dosen ketiga (team teaching) bila ada'],
            ['Periode / Fase', 'Opsional', 'Satu semester, Sebelum UTS, Setelah UTS', 'Default: Satu semester (jika dikosongkan)'],
        ];
        $refSheet->fromArray($guideRows, null, 'A4');
        $refSheet->getStyle('A3:D'.(count($guideRows) + 3))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Daftar Ruangan Terdaftar
        $startRoomRow = count($guideRows) + 6;
        $refSheet->setCellValue("A{$startRoomRow}", 'DAFTAR RUANGAN TERDAFTAR DI SICMA:');
        $refSheet->getStyle("A{$startRoomRow}")->getFont()->setBold(true)->setSize(11);

        $roomHeaders = ['Nama Ruangan', 'Kode Ruang', 'Kapasitas', 'Jenis Ruang', 'Gedung'];
        $refSheet->fromArray([$roomHeaders], null, 'A'.($startRoomRow + 1));
        $refSheet->getStyle('A'.($startRoomRow + 1).':E'.($startRoomRow + 1))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0284C7']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rooms = Room::query()->where('is_active', true)->with('building')->orderBy('name')->get();
        $roomData = $rooms->map(fn (Room $r): array => [
            $r->name,
            $r->code ?? '-',
            ($r->capacity ?? 0).' kursi',
            $r->type?->label() ?? 'Kuliah',
            $r->building?->name ?? 'Gedung AE',
        ])->all();

        if ($roomData !== []) {
            $refSheet->fromArray($roomData, null, 'A'.($startRoomRow + 2));
            $endRoomRow = $startRoomRow + 1 + count($roomData);
            $refSheet->getStyle('A'.($startRoomRow + 1).":E{$endRoomRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        }

        // Daftar Kelas Terdaftar
        $startClassRow = (isset($endRoomRow) ? $endRoomRow : $startRoomRow + 2) + 3;
        $refSheet->setCellValue("A{$startClassRow}", 'DAFTAR KELAS TERDAFTAR DI SICMA:');
        $refSheet->getStyle("A{$startClassRow}")->getFont()->setBold(true)->setSize(11);

        $classHeaders = ['Nama Kelas', 'Kode Kelas', 'Program Studi', 'Angkatan'];
        $refSheet->fromArray([$classHeaders], null, 'A'.($startClassRow + 1));
        $refSheet->getStyle('A'.($startClassRow + 1).':D'.($startClassRow + 1))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $classes = ClassGroup::query()->where('is_active', true)->orderBy('name')->get();
        $classData = $classes->map(fn (ClassGroup $c): array => [
            $c->name,
            $c->code ?? '-',
            $c->study_program ?? '-',
            (string) ($c->cohort_year ?? '-'),
        ])->all();

        if ($classData !== []) {
            $refSheet->fromArray($classData, null, 'A'.($startClassRow + 2));
            $endClassRow = $startClassRow + 1 + count($classData);
            $refSheet->getStyle('A'.($startClassRow + 1).":D{$endClassRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        }

        foreach (range('A', 'E') as $col) {
            $refSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
