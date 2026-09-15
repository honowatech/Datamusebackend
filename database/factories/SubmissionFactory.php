<?php

namespace Database\Factories;

use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        $started = Carbon::parse(fake()->dateTimeBetween('-20 days', '-1 hour'))->setTimezone('Africa/Douala');
        $ended = $started->copy()->addSeconds(fake()->numberBetween(660, 1000));
        $quartier = fake()->randomElement(['bonamoussadi', 'akwa', 'deido', 'makepe', 'logpom', 'kotto']);

        return [
            'uuid' => (string) Str::uuid(),
            'survey_id' => Survey::factory(),
            'survey_version_id' => fn (array $attrs) => SurveyVersion::query()
                ->where('survey_id', $attrs['survey_id'])
                ->published()
                ->value('id')
                ?? SurveyVersion::factory()->published()->create(['survey_id' => $attrs['survey_id']])->id,
            'project_id' => fn (array $attrs) => Survey::withTrashed()->find($attrs['survey_id'])?->project_id,
            'enumerator_id' => User::factory(),
            'device_id' => null,
            'channel' => SubmissionChannel::Mobile,
            'status' => SubmissionStatus::Submitted,
            'fiche_code' => sprintf('DLA-%s-%02d', Str::upper(Str::random(3)), fake()->numberBetween(1, 99)),
            'zone' => Str::ucfirst($quartier),
            'language' => 'fr',
            'started_at' => $started,
            'ended_at' => $ended,
            'duration_seconds' => $ended->getTimestamp() - $started->getTimestamp(),
            'geo_lat' => fake()->randomFloat(7, 4.02, 4.10),
            'geo_lng' => fake()->randomFloat(7, 9.68, 9.80),
            'geo_accuracy' => fake()->randomFloat(2, 4, 45),
            'geo' => null,
            'answers' => self::baseAnswers($quartier),
            'end_reason' => null,
            'answers_hash' => null,
            'client_updated_at' => $ended->copy()->addSeconds(30),
            'received_at' => $ended->copy()->addMinutes(fake()->numberBetween(1, 240)),
            'flags' => [],
            'suspicion_score' => 0,
            'quality_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'device_time_offset_ms' => fake()->numberBetween(-5000, 5000),
        ];
    }

    /**
     * Jeu de réponses MunaGo cohérent pour un entretien complet (sans acompte).
     *
     * @return array<string, mixed>
     */
    public static function baseAnswers(string $quartier = 'bonamoussadi'): array
    {
        return [
            'ville' => 'douala',
            'quartier' => $quartier,
            'lieu' => fake()->streetName(),
            'lieu_enrolement' => 'marche',
            'reseau_origine' => 'direct',
            'consentement' => 'oui',
            'deja_contacte' => 'non',
            'f1_enfant_scolarise' => 'oui',
            'f2_capacite' => ['nounou', 'ecole_privee'],
            'nb_signaux' => 2,
            'resultat_filtre' => 'valide',
            'sexe' => fake()->randomElement(['h', 'f']),
            'age_approx' => fake()->numberBetween(25, 55),
            'nb_enfants_scolarises' => fake()->numberBetween(1, 4),
            'p1_ecole_privee' => 'oui',
            'p1_aide_nounou' => 'oui',
            'p1_chauffeur' => 'non',
            'p2_smartphone' => 'android',
            'd2_incident' => 'non',
            'q5_reaction' => fake()->sentence(),
            'q6_freins' => fake()->sentence(),
            'q7_portera' => 'oui_sans_doute',
            'f1_prix_appareil' => 'cher_acceptable',
            'f2_prix_reponse' => 'montant',
            'f2_prix_rupture' => 40000,
            'q10_moyen_paiement' => 'mtn_momo',
            'q13_decision' => 'non_clair',
            'acompte_verse' => false,
            'q14_motif' => fake()->sentence(),
            'souhait_sans_acompte' => 'non',
        ];
    }

    // ------------------------------------------------------------------ états

    /** Entretien arrêté par un `stop` (hors cible) : réponses partielles, comptée hors « valid ». */
    public function screenedOut(string $stopKey = 'stop_f1_enfant'): static
    {
        return $this->state(function (array $attrs) use ($stopKey) {
            $started = Carbon::parse($attrs['started_at']);
            $ended = $started->copy()->addSeconds(fake()->numberBetween(60, 150));

            return [
                'status' => SubmissionStatus::ScreenedOut,
                'end_reason' => $stopKey,
                'fiche_code' => null,
                'ended_at' => $ended,
                'duration_seconds' => $ended->getTimestamp() - $started->getTimestamp(),
                'client_updated_at' => $ended->copy()->addSeconds(10),
                'answers' => [
                    'ville' => 'douala',
                    'quartier' => $attrs['answers']['quartier'] ?? 'akwa',
                    'lieu_enrolement' => 'marche',
                    'reseau_origine' => 'direct',
                    'consentement' => 'oui',
                    'deja_contacte' => 'non',
                    'f1_enfant_scolarise' => 'non',
                    'resultat_filtre' => 'hors_cible',
                ],
            ];
        });
    }

    /** Entretien plus court que `timing.min_duration_seconds` (600 s) : drapeau too_fast. */
    public function tooFast(): static
    {
        return $this->state(function (array $attrs) {
            $started = Carbon::parse($attrs['started_at']);
            $ended = $started->copy()->addSeconds(fake()->numberBetween(120, 300));

            return [
                'ended_at' => $ended,
                'duration_seconds' => $ended->getTimestamp() - $started->getTimestamp(),
                'client_updated_at' => $ended->copy()->addSeconds(10),
                'flags' => [Submission::FLAG_TOO_FAST],
                'suspicion_score' => 40,
            ];
        });
    }

    /** Test d'engagement réussi : acompte versé (questions T renseignées). */
    public function withDeposit(): static
    {
        return $this->state(function (array $attrs) {
            $ended = Carbon::parse($attrs['ended_at']);

            return [
                'answers' => array_merge($attrs['answers'] ?? [], [
                    'q13_decision' => 'oui_paiement',
                    'acompte_verse' => true,
                    'montant_recu' => 5000,
                    'operateur_momo' => 'mtn',
                    'reference_momo' => 'MP'.fake()->numerify('########'),
                    'num_whatsapp' => '6'.fake()->numerify('########'),
                    'nom_compte_momo' => fake()->name(),
                    'date_limite_retrait' => $ended->copy()->addDays(7)->toDateString(),
                    'q15_observation' => ['pose_questions'],
                    'q15_chaleur_oui' => 'enthousiaste',
                ]),
            ];
        });
    }

    public function validated(?User $reviewer = null): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Validated,
            'reviewed_by' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(?User $reviewer = null, string $notes = 'Fiche incohérente'): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Rejected,
            'reviewed_by' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
            'quality_notes' => $notes,
        ]);
    }

    public function public(): static
    {
        return $this->state([
            'channel' => SubmissionChannel::Public,
            'enumerator_id' => null,
            'device_id' => null,
            'fiche_code' => null,
            'zone' => null,
        ]);
    }
}
