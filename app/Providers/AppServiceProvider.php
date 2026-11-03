<?php

namespace App\Providers;

use App\Models\User;
use App\View\Composers\PortalComposer;
use Illuminate\Auth\Notifications\ResetPassword;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
