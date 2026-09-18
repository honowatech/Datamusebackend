<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TargetDatabase extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * Get the user that owns the target database connection.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Source de données matérialisée d'un questionnaire (module Enquêtes) pointant sur cette base.
     */
    public function surveyDatasource(): HasOne
    {
        return $this->hasOne(SurveyDatasource::class);
    }

    /**
     * Bases visibles par un utilisateur (B-09b, plan § 5.2) :
     *   - celles dont il est propriétaire (comportement historique des imports Excel/CSV) ;
     *   - **plus** les sources matérialisées des questionnaires des projets où il est analyste ou
     *     superviseur, même si le fichier appartient au propriétaire du projet.
     *
     * Seul point de contact du module Enquêtes avec le code existant (`ChatController`,
     * `DatabaseConnectionController`) : sans ce scope, un analyste non propriétaire ne pourrait pas
     * ouvrir « Enquête : {titre} » dans le chat SQL.
     *
     * @param  Builder<TargetDatabase>  $query
     * @return Builder<TargetDatabase>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        // L'admin global supervise tous les projets (Gate::before), donc toutes leurs sources ;
        // les imports Excel/CSV personnels des autres utilisateurs restent privés.
        $projectIds = $user->isAdmin() ? null : self::supervisedProjectIds($user);

        return $query->where(function (Builder $q) use ($user, $projectIds) {
            $q->where('target_databases.user_id', $user->id);

            if ($projectIds === null || $projectIds !== []) {
                $q->orWhereIn(
                    'target_databases.id',
                    SurveyDatasource::query()
                        ->whereNotNull('target_database_id')
                        ->when($projectIds !== null, fn ($sub) => $sub->whereIn(
                            'survey_id',
                            Survey::query()->withTrashed()->whereIn('project_id', $projectIds)->select('id'),
                        ))
                        ->select('target_database_id'),
                );
            }
        });
    }

    /**
     * Projets où l'utilisateur est analyste ou superviseur (propriétaire inclus : analyste implicite).
     *
     * @return list<int>
     */
    private static function supervisedProjectIds(User $user): array
    {
        $owned = SurveyProject::query()->where('owner_id', $user->id)->pluck('id');

        $member = ProjectMember::query()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('role', [ProjectRole::Analyste->value, ProjectRole::Superviseur->value])
            ->pluck('project_id');

        return $owned->merge($member)->unique()->map(fn ($id) => (int) $id)->values()->all();
    }
}
