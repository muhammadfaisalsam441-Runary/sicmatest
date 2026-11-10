<?php

namespace App\Providers;

use App\Models\Academic\CalendarException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Academic\TimeSlot;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerAvailability;
use App\Models\Lecturer\LecturerExpertise;
use App\Models\Lecturer\LecturerLoadTarget;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Reservation\Reservation;
use App\Models\Room\Building;
use App\Models\Room\Facility;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\ImportRow;
use App\Models\Scheduling\OptimizationJob;
use App\Models\Scheduling\ScheduleIssue;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\Scheduling\SchedulingRule;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\ClassRepresentative;
use App\Models\User\EmailVerificationCode;
use App\Models\User\KetiApplication;
use App\Models\User\Setting;
use App\Models\User\User;
use App\Policies\AppNotificationPolicy;
use App\Policies\ReservationPolicy;
use App\View\Composers\PortalComposer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'App\\Models\\') && substr_count($class, '\\') === 2) {
                $baseName = class_basename($class);
                $map = [
                    'Period' => Period::class,
                    'Course' => Course::class,
                    'CourseOffering' => CourseOffering::class,
                    'ClassGroup' => ClassGroup::class,
                    'TimeSlot' => TimeSlot::class,
                    'CalendarException' => CalendarException::class,
                    'Lecturer' => Lecturer::class,
                    'LecturerAvailability' => LecturerAvailability::class,
                    'LecturerExpertise' => LecturerExpertise::class,
                    'LecturerLoadTarget' => LecturerLoadTarget::class,
                    'TeachingAssignment' => TeachingAssignment::class,
                    'LeadershipTerm' => LeadershipTerm::class,
                    'Building' => Building::class,
                    'Room' => Room::class,
                    'Facility' => Facility::class,
                    'RoomBlock' => RoomBlock::class,
                    'RoomClosure' => RoomClosure::class,
                    'Reservation' => Reservation::class,
                    'ScheduleVersion' => ScheduleVersion::class,
                    'ScheduleSession' => ScheduleSession::class,
                    'ScheduleIssue' => ScheduleIssue::class,
                    'OptimizationJob' => OptimizationJob::class,
                    'Import' => Import::class,
                    'ImportRow' => ImportRow::class,
                    'SchedulingRule' => SchedulingRule::class,
                    'User' => User::class,
                    'KetiApplication' => KetiApplication::class,
                    'ClassRepresentative' => ClassRepresentative::class,
                    'EmailVerificationCode' => EmailVerificationCode::class,
                    'AppNotification' => AppNotification::class,
                    'AuditLog' => AuditLog::class,
                    'Setting' => Setting::class,
                ];
                if (isset($map[$baseName])) {
                    class_alias($map[$baseName], $class);
                }
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory');

        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(AppNotification::class, AppNotificationPolicy::class);

        View::composer(['layouts.keti', 'layouts.admin'], PortalComposer::class);

        Gate::define('publish-schedule', fn (User $user): bool => $user->isAdmin() && $user->can_publish && $user->is_active);

        Password::defaults(fn () => Password::min(8));

        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => (new MailMessage)
            ->subject('Atur ulang kata sandi SICMA')
            ->greeting('Halo, '.$user->name)
            ->line('Kami menerima permintaan atur ulang kata sandi untuk akun SICMA kamu.')
            ->action('Buka tautan atur ulang', route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line('Tautan berlaku '.config('auth.passwords.users.expire').' menit. Abaikan email ini jika kamu tidak memintanya.')
            ->salutation('SICMA • JTIK UNM'));
    }
}
