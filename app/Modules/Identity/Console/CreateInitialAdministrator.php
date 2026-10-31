<?php

namespace App\Modules\Identity\Console;

use App\Models\AccessRole;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateInitialAdministrator extends Command
{
    protected $signature = 'erp:admin:create';

    protected $description = 'Create the first ERP system administrator through an interactive local console';

    public function handle(AuditLogger $audit): int
    {
        if (! $this->input->isInteractive() || ! app()->runningInConsole()) {
            $this->error('This one-time bootstrap command requires an interactive console.');

            return self::FAILURE;
        }
        if (User::query()->exists()) {
            $this->error('Bootstrap is closed because at least one user already exists.');

            return self::FAILURE;
        }
        $role = AccessRole::query()->where('code', 'super-admin')->where('is_active', true)->first();
        if (! $role || ! $role->permissions()->exists()) {
            $this->error('Run the standard database seeders before creating the initial administrator.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = mb_strtolower(trim((string) $this->ask('Administrator email')));
        $password = (string) $this->secret('Choose a strong password (minimum 14 characters)');
        $confirmation = (string) $this->secret('Confirm password');
        if ($name === '' || mb_strlen($name) > 150 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || $password !== $confirmation) {
            $this->error('Name, email or password confirmation is invalid.');

            return self::FAILURE;
        }
        try {
            validator(['password' => $password], ['password' => ['required', Password::min(14)->mixedCase()->numbers()->symbols()]])->validate();
        } catch (ValidationException) {
            $this->error('Use at least 14 characters with upper and lower case letters, a number and a symbol.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $audit): void {
            $lockedRole = AccessRole::query()->where('code', 'super-admin')->where('is_active', true)->lockForUpdate()->first();
            if (! $lockedRole || ! $lockedRole->permissions()->exists()) {
                throw new \RuntimeException('System administrator role configuration changed during bootstrap.');
            }
            if (User::query()->lockForUpdate()->exists()) {
                throw new \RuntimeException('An account was created while bootstrap was in progress.');
            }
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
            $user->accessRoles()->attach($lockedRole->id, ['assigned_by' => $user->id]);
            $audit->record('users', $user->id, 'create', null, ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'roles' => ['super-admin']]);
        });

        $this->info('Initial administrator created. Store the credentials in your approved password manager.');

        return self::SUCCESS;
    }
}
