<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Restablece la contraseña de cualquier usuario del panel (admin o cliente) desde la línea de
 * comandos. Es el salvavidas cuando el correo no está disponible (SMTP bloqueado / MAIL_MAILER=log)
 * y el usuario no puede usar el "olvidé mi contraseña" por email, o cuando el único administrador
 * perdió el acceso.
 *
 *   php artisan usuario:reset-password correo@empresa.com                 # genera una contraseña segura
 *   php artisan usuario:reset-password correo@empresa.com --password=...  # define una concreta
 */
class ResetUserPassword extends Command
{
    protected $signature = 'usuario:reset-password
                            {email : Email del usuario cuya contraseña se restablece}
                            {--password= : Contraseña nueva (si se omite, se genera una segura)}';

    protected $description = 'Restablece la contraseña de un usuario del panel (recuperación de emergencia sin correo).';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No existe un usuario con el email {$email}.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));
        if (strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("✔ Contraseña restablecida para {$email} (id {$user->id}).");
        $this->line('  Nueva contraseña: '.$password);
        $this->warn('  Entrégala por un canal seguro y pídele al usuario que la cambie al ingresar (Ajustes → Contraseña).');

        return self::SUCCESS;
    }
}
