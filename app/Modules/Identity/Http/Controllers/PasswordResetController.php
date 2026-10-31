<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\PasswordResetLinkRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function requestForm(): View
    {
        return view('module.identity::password.request');
    }

    public function resetForm(Request $request): View
    {
        return view('module.identity::password.reset', ['token' => $request->route('token'), 'email' => $request->query('email')]);
    }

    public function sendLink(PasswordResetLinkRequest $request): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $request->validated('email'), 'is_active' => true]);

        return back()->with('status', 'If an active account matches that email address, reset instructions have been sent.');
    }

    public function reset(ResetPasswordRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $status = Password::reset([
            'email' => $data['email'], 'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'], 'token' => $data['token'], 'is_active' => true,
        ], function (User $user) use ($data, $audit): void {
            DB::transaction(function () use ($data, $audit, $user): void {
                $before = ['password_changed' => true];
                $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $audit->record('users', $user->id, 'change', $before, ['password_changed' => true]);
            });
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? to_route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
