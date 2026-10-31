<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function accessRoles(): BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'user_roles')->withPivot('assigned_by')->withTimestamps();
    }

    public function hasRole(string $code): bool
    {
        return $this->accessRoles()->where('access_roles.code', $code)->where('access_roles.is_active', true)->exists();
    }

    public function hasPermissionTo(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $this->loadMissing('accessRoles.permissions');

        return $this->accessRoles->contains(fn (AccessRole $role) => $role->is_active
            && $role->permissions->contains('code', $permission));
    }

    public function assignedRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
