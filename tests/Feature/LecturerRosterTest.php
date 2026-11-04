<?php

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Lecturer;
use App\Models\User;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\LecturerRosterSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
});

test('daftar dosen JTIK melengkapi NIP, pangkat, dan jabatan dosen jadwal tanpa menggandakan nama', function () {
    $this->seed([AcademicSeeder::class, LecturerRosterSeeder::class]);

    $head = Lecturer::query()->where('name', AcademicSeeder::HEAD_OF_DEPARTMENT)->sole();

    expect(Lecturer::query()->count())->toBe(107)
        ->and(Lecturer::query()->whereNotNull('nip')->count())->toBe(51)
        ->and(Lecturer::query()->where('homebase', LecturerHomebase::Jtik)->count())->toBe(51)
        ->and($head->nip)->toBe('196910181994031001')
        ->and($head->rank)->toBe('Pembina Tingkat I')
        ->and($head->grade)->toBe('IV/b')
        ->and($head->grade_since->toDateString())->toBe('2024-06-01')
        ->and($head->functional_position)->toBe('Lektor Kepala')
        ->and($head->functional_position_since->toDateString())->toBe('2006-12-01');
});

test('ejaan berbeda di daftar dosen tetap tertaut ke dosen jadwal yang sama', function () {
    $this->seed([AcademicSeeder::class, LecturerRosterSeeder::class]);

    expect(Lecturer::query()->where('name', 'Dr. Ir. Mustari S. Lamada, S.Pd., M.T.')->value('nip'))->toBe('197505052005011001')
        ->and(Lecturer::query()->where('name', 'Asmaul Husna Nasrullah, S.Kom., M.Kom.')->value('nip'))->toBe('198610112023211020')
        ->and(Lecturer::query()->where('name', 'like', '%Asmaul%')->count())->toBe(1);
});

test('dosen JTIK yang tidak mengajar semester ini ditambahkan, dosen di luar daftar tetap belum diverifikasi asalnya', function () {
    $this->seed([AcademicSeeder::class, LecturerRosterSeeder::class]);

    $new = Lecturer::query()->where('name', 'Suhartono, S.Kom, M.Kom.')->sole();

    expect($new->homebase)->toBe(LecturerHomebase::Jtik)
        ->and($new->source_name)->toBe('SUHARTONO, S.Kom, M.Kom.')
        ->and($new->functional_position)->toBe('Lektor')
        ->and(Lecturer::query()->where('name', 'Muh. Asyam Samjas, S.Pd., M.Pd.')->value('functional_position'))->toBe('Belum ada')
        ->and(Lecturer::query()->where('name', 'Prof. Dr. Idawati Garim, M.Si.')->sole()->homebase)->toBeNull();
});

test('halaman Data Dosen menampilkan NIP dan jabatan fungsional dengan filter Homebase JTIK', function () {
    $this->seed([AcademicSeeder::class, LecturerRosterSeeder::class]);

    $this->actingAs($this->admin)
        ->get(route('admin.lecturers.index', ['filter' => 'jtik']))
        ->assertOk()
        ->assertSee('NIP 196910181994031001')
        ->assertSee('Lektor Kepala')
        ->assertSee('Pembina Tingkat I • IV/b')
        ->assertDontSee('Idawati Garim');

    $this->get(route('admin.lecturers.index', ['q' => '199204012018032001']))
        ->assertOk()
        ->assertSee('Alifya NFH');
});

test('admin mengubah NIP dan data kepegawaian; NIP harus 18 angka dan unik', function () {
    $lecturer = Lecturer::factory()->create(['name' => 'Rina Kartika']);
    Lecturer::factory()->create(['nip' => '199001012020012001']);
    $payload = ['name' => 'Rina Kartika', 'profile_status' => LecturerProfileStatus::Verified->value, 'homebase' => 'jtik', 'rank' => 'Penata', 'grade' => 'III/c', 'functional_position' => 'Lektor'];

    $this->actingAs($this->admin)->put(route('admin.lecturers.update', $lecturer), [...$payload, 'nip' => '12345'])->assertSessionHasErrors(['nip' => 'NIP terdiri atas 18 angka.']);
    $this->put(route('admin.lecturers.update', $lecturer), [...$payload, 'nip' => '199001012020012001'])->assertSessionHasErrors('nip');
    $this->put(route('admin.lecturers.update', $lecturer), [...$payload, 'nip' => '199205152022031007'])->assertSessionHasNoErrors();

    expect($lecturer->fresh())
        ->nip->toBe('199205152022031007')
        ->rank->toBe('Penata')
        ->grade->toBe('III/c')
        ->functional_position->toBe('Lektor');
});

test('impor spreadsheet mencocokkan dosen lewat NIP dan mempertahankan nama dari jadwal', function () {
    $lecturer = Lecturer::factory()->unverified()->create(['name' => 'Dr. Iwan Suhardi, ST., MT.', 'nip' => '197002071997021001']);
    $csv = "Nama,NIP,Pangkat,Gol.,Jabatan,Homebase\n\"DR. IWAN SUHARDI, S.T., M.T.\",197002071997021001,Pembina Tingkat I,IV/b,LEKTOR KEPALA,JTIK\n\"BARU SEKALI, M.Kom.\",199901012025061001,CPNS,III/b,BELUM,JTIK\n";

    $this->actingAs($this->admin)
        ->post(route('admin.lecturers.import'), ['file' => UploadedFile::fake()->createWithContent('dosen.csv', $csv)])
        ->assertSessionHas('toast');

    expect($lecturer->fresh())
        ->name->toBe('Dr. Iwan Suhardi, ST., MT.')
        ->grade->toBe('IV/b')
        ->functional_position->toBe('LEKTOR KEPALA')
        ->profile_status->toBe(LecturerProfileStatus::Verified)
        ->and(Lecturer::query()->where('nip', '199901012025061001')->value('functional_position'))->toBe('Belum ada');
});
