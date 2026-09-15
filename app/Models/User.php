<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'locale',
        'gemini_api_key',
        'deepseek_api_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'gemini_api_key',
        'deepseek_api_key',
    ];

    /**
     * Valeurs par défaut alignées sur la migration (role analyste, locale fr).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'analyste',
        'locale' => 'fr',
    ];

    /**
     * Cache par requête des rôles projet : [project_id => ProjectRole|null].
     *
     * @var array<int, ProjectRole|null>
     */
    private array $projectRoleCache = [];

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
            'role' => UserRole::class,
            'gemini_api_key' => 'encrypted',
            'deepseek_api_key' => 'encrypted',
        ];
    }

    // ------------------------------------------------------------------ relations (existant)

    /**
     * Get the target database connections owned by this user.
     */
    public function targetDatabases(): HasMany
    {
        return $this->hasMany(TargetDatabase::class);
    }

    // ------------------------------------------------------------------ relations (module Enquêtes)

    public function projectsOwned(): HasMany
    {
        return $this->hasMany(SurveyProject::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Projets dont l'utilisateur est membre, avec le pivot (role, zone, status).
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(SurveyProject::class, 'project_members', 'user_id', 'project_id')
            ->withPivot(['role', 'zone', 'status'])
            ->withTimestamps();
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'invited_by');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EnumeratorAssignment::class);
    }

    /**
     * Questionnaires auxquels l'utilisateur est affecté comme enquêteur.
     */
    public function assignedSurveys(): BelongsToMany
    {
        return $this->belongsToMany(Survey::class, 'enumerator_assignments', 'user_id', 'survey_id')
            ->withPivot(['zone', 'quota_target', 'starts_at', 'ends_at'])
            ->withTimestamps();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'enumerator_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUpEntry::class, 'enumerator_id');
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class);
    }

    // ------------------------------------------------------------------ rôles

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isEnumerator(): bool
    {
        return $this->role === UserRole::Enqueteur;
    }

    /**
     * Rôle de l'utilisateur dans un projet (membre actif), mémoïsé pour la durée de la requête.
     * Le propriétaire du projet est traité comme analyste s'il n'a pas de ligne membre.
     */
    public function projectRole(int $projectId): ?ProjectRole
    {
        if (array_key_exists($projectId, $this->projectRoleCache)) {
            return $this->projectRoleCache[$projectId];
        }

        $member = ProjectMember::query()
            ->where('project_id', $projectId)
            ->where('user_id', $this->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first(['role']);

        $role = $member?->role;

        if ($role === null && SurveyProject::query()->whereKey($projectId)->where('owner_id', $this->id)->exists()) {
            $role = ProjectRole::Analyste;
        }

        return $this->projectRoleCache[$projectId] = $role;
    }

    /**
     * Vide le cache des rôles projet (après modification d'un membre).
     */
    public function forgetProjectRoles(): static
    {
        $this->projectRoleCache = [];

        return $this;
    }

    public function hasProjectRole(int $projectId, ProjectRole ...$roles): bool
    {
        $role = $this->projectRole($projectId);

        return $role !== null && ($roles === [] || in_array($role, $roles, true));
    }
}
