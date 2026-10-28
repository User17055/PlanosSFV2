<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'franchise_id', 'customer_id', 'active', 'email_verified_at', 'employee_code', 'phone', 'job_title', 'hired_at', 'monthly_sales_target_cents'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function franchises()
    {
        return $this->belongsToMany(Franchise::class, 'user_franchises')->withPivot('active', 'permissions');
    }

    public function hasFranchise(int $id): bool
    {
        return $this->active && Franchise::whereKey($id)->where('active', true)->exists() && ($this->role === 'central_admin' || $this->franchises()->where('franchises.id', $id)->wherePivot('active', true)->exists());
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
            'password' => 'hashed',
            'active' => 'boolean',
            'hired_at' => 'date',
            'monthly_sales_target_cents' => 'integer',
        ];
    }
}
