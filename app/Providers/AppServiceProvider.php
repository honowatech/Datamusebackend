<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyReport;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Policies\SurveyPolicy;
use App\Policies\SurveyProjectPolicy;
use App\Policies\SurveyReportPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        $this->configureRateLimiting();
        $this->configureAuthorization();
    }

    /**
     * Limiteurs nommés (plan § 2 « Débit ») : `throttle:<nom>` dans routes/api.php.
     */
    private function configureRateLimiting(): void
    {
        $byUser = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?: $request->ip());
        $byIp = static fn (Request $request): string => (string) $request->ip();

        // Défaut des endpoints web : 60/min par utilisateur (équivalent de throttle:60,1 existant).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($byUser($request)));

        // Synchronisation mobile : 600/min par utilisateur.
        RateLimiter::for('mobile', fn (Request $request) => Limit::perMinute(600)->by($byUser($request)));

        // Collecte publique (sans auth) : par IP.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(30)->by($byIp($request)));
        RateLimiter::for('public-write', fn (Request $request) => Limit::perMinute(10)->by($byIp($request)));

        // Opérations IA (génération, traduction, synthèse, rapports…) : 5/min par utilisateur.
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(5)->by($byUser($request)));
    }

    /**
     * Policies du module Enquêtes + capacité globale `create-project`. L'admin global contourne tout.
     */
    private function configureAuthorization(): void
    {
        Gate::policy(SurveyProject::class, SurveyProjectPolicy::class);
        Gate::policy(Survey::class, SurveyPolicy::class);
        Gate::policy(Submission::class, SubmissionPolicy::class);
        Gate::policy(SurveyReport::class, SurveyReportPolicy::class);

        Gate::before(static fn (User $user, string $ability) => $user->isAdmin() ? true : null);

        Gate::define('create-project', static fn (User $user): bool => $user->isAdmin() || $user->role === UserRole::Analyste);
    }
}
