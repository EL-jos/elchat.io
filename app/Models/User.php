<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
        ];
    }

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }
    // 🔐 ROLE
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // 🧑‍💼 ACCOUNT (uniquement si admin)
    public function ownedAccount(): HasOne
    {
        return $this->hasOne(Account::class, 'owner_user_id');
    }

    // 🌐 SITES (visiteur)
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class)
            ->withPivot(['first_seen_at', 'last_seen_at']);
    }
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Retourne l'identifiant unique de l'utilisateur pour le JWT
     */
    public function getJWTIdentifier()
    {
        // TODO: Implement getJWTIdentifier() method.
        return $this->getKey();
    }
    /**
     * Retourne un tableau de claims personnalisés à ajouter au JWT
     */
    public function getJWTCustomClaims()
    {
        // TODO: Implement getJWTCustomClaims() method.
        return [
            'role' => $this->role?->name ?? 'unknown',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    public function isVisitor(): bool
    {
        return $this->role?->name === 'visitor';
    }

}
