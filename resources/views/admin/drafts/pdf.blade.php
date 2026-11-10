<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>SICMA - Jadwal {{ $version->period->name }} v{{ $version->version_no }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 12mm 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #152b43; font: 10pt "DejaVu Sans", Arial, sans-serif; }
        .heading { border-bottom: 2px solid #245987; margin-bottom: 12pt; padding-bottom: 8pt; }
        .brand { color: #245987; font-size: 9pt; font-weight: bold; letter-spacing: 1.4pt; }
        h1 { font-size: 20pt; line-height: 1.2; margin: 3pt 0 4pt; }
        .subtitle { color: #465c73; margin: 0; }
        table { border-collapse: collapse; font-size: 8.5pt; line-height: 1.3; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        th { background: #17395d; color: white; padding: 5pt; text-align: left; }
        td { border-bottom: 1px solid #dbe5ee; overflow-wrap: break-word; padding: 4pt 5pt; vertical-align: top; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        .group { page-break-after: avoid; }
        .group td { background: #dbeaf6; color: #17395d; font-weight: bold; }
        .band td { background: #f2f6fa; }
        .lecturers { white-space: pre-line; }
    </style>
</head>
<body>
    <header class="heading">
        <div class="brand">SICMA · JADWAL PERKULIAHAN</div>
        <h1>{{ $version->period->name }}</h1>
        <p class="subtitle">{{ $subtitle }}</p>
    </header>
    <table>
        <thead>
            <tr>
                <th scope="col" style="width: 9%">Hari</th>
                <th scope="col" style="width: 10%">Jam (WITA)</th>
                <th scope="col" style="width: 20%">Mata Kuliah</th>
                <th scope="col" style="width: 13%">Kelas Peserta</th>
                <th scope="col" style="width: 9%">Ruang</th>
                <th scope="col" style="width: 29%">Tim Pengajar</th>
                <th scope="col" style="width: 10%">Fase</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group => $rows)
                <tr class="group"><td colspan="7">{{ $group }} · {{ $rows->count() }} sesi</td></tr>
                @foreach ($rows as [$day, $time, $course, $classes, $room, $lecturers, $phase])
                    <tr @class(['band' => $loop->even])>
                        <td>{{ $day }}</td><td>{{ $time }}</td><td>{{ $course }}</td>
                        <td>{{ $classes }}</td><td>{{ $room }}</td><td class="lecturers">{{ $lecturers }}</td><td>{{ $phase }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="7">Belum ada sesi pada versi ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
