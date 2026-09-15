<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\SurveyProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Projet d'enquête : regroupe des questionnaires et des membres (analystes, superviseurs, enquêteurs).
 */
class SurveyProject extends Model
{
    /** @use HasFactory<SurveyProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'client_name',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id');
    }

    /**
     * Utilisateurs membres, avec le pivot (role, zone, status).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members', 'project_id', 'user_id')
            ->withPivot(['role', 'zone', 'status'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'project_id');
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class, 'project_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'project_id');
    }

    /**
     * Rôle effectif de l'utilisateur dans le projet (le propriétaire est analyste implicite).
     */
    public function roleOf(User $user): ?ProjectRole
    {
        if ($user->id === $this->owner_id) {
            return ProjectRole::Analyste;
        }

        return $user->projectRole($this->id);
    }
}
