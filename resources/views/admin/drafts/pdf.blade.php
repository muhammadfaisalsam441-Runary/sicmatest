<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $department }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 9mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000000; font: 7.2pt "DejaVu Sans", Arial, sans-serif; }
        .heading { margin-bottom: 8pt; text-align: center; }
        .heading h1 { font-size: 13pt; font-weight: bold; line-height: 1.25; margin: 0; text-transform: uppercase; }
        .heading h2 { font-size: 12pt; font-weight: bold; line-height: 1.25; margin: 1pt 0 0; text-transform: uppercase; }
        .heading .sub { color: #465c73; font-size: 8pt; font-style: italic; margin: 3pt 0 0; }
        table { border-collapse: collapse; font-size: 7pt; line-height: 1.25; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        th {
            background: #a9c4e2;
            border: 0.75pt solid #000000;
            color: #000000;
            font-weight: bold;
            padding: 4pt 3pt;
            text-align: center;
            vertical-align: middle;
        }
        td {
            border: 0.6pt solid #000000;
            overflow-wrap: break-word;
            padding: 2.5pt 3.5pt;
            vertical-align: middle;
        }
        tr { break-inside: avoid; page-break-inside: avoid; }
        tbody tr.band td { background: #fff5d6; }
        .center { text-align: center; }
        .left { text-align: left; }
    </style>
</head>
<body>
    <header class="heading">
        <h1>{{ $title }}</h1>
        <h2>{{ $department }}</h2>
        @if ($view !== 'kelas')
            <p class="sub">Tinjauan Per {{ $viewLabel }}</p>
        @endif
    </header>
    <table>
        <thead>
            <tr>
                <th scope="col" style="width: 3%">NO</th>
                <th scope="col" style="width: 10%">KELAS</th>
                <th scope="col" style="width: 5.5%">HARI</th>
                <th scope="col" style="width: 5.5%">JAM</th>
                <th scope="col" style="width: 8.5%">WITA</th>
                <th scope="col" style="width: 8.5%">RUANG</th>
                <th scope="col" style="width: 9.5%">KODE MK</th>
                <th scope="col" style="width: 15.5%">MATA KULIAH</th>
                <th scope="col" style="width: 3.5%">SKS</th>
                <th scope="col" style="width: 12.5%">DOSEN PENGAMPU</th>
                <th scope="col" style="width: 11.5%">DOSEN MITRA</th>
                <th scope="col" style="width: 6.5%">DOSEN MITRA 2</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr @class(['band' => $row['shaded'] ?? $loop->odd])>
                    <td class="center">{{ $row['no'] }}</td>
                    <td class="center">{{ $row['kelas'] }}</td>
                    <td class="center">{{ $row['hari'] }}</td>
                    <td class="center">{{ $row['jam'] }}</td>
                    <td class="center">{{ $row['wita'] }}</td>
                    <td class="center">{{ $row['ruang'] }}</td>
                    <td class="center">{{ $row['kode_mk'] }}</td>
                    <td class="left">{{ $row['mata_kuliah'] }}</td>
                    <td class="center">{{ $row['sks'] }}</td>
                    <td class="left">{{ $row['dosen_pengampu'] }}</td>
                    <td class="left">{{ $row['dosen_mitra'] }}</td>
                    <td class="left">{{ $row['dosen_mitra_2'] }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="center">Belum ada sesi pada versi ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
