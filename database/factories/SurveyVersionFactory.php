<?php

namespace Database\Factories;

use App\Enums\SurveyStatus;
use App\Enums\VersionStatus;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SurveyVersion>
 */
class SurveyVersionFactory extends Factory
{
    protected $model = SurveyVersion::class;

    /** Chemin de la fixture MunaGo (source unique du contrat, racine du monorepo). */
    public const MUNAGO_FIXTURE = '../docs/fixtures/munago.v1.dfs.json';

    /** @var array<string, mixed>|null */
    private static ?array $munagoCache = null;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            // Numéro suivant pour ce questionnaire (unique survey_id + version).
            'version' => fn (array $attrs) => (int) (SurveyVersion::query()
                ->where('survey_id', $attrs['survey_id'])
                ->max('version') ?? 0) + 1,
            'status' => VersionStatus::Draft,
            'definition' => fn (array $attrs) => self::minimalDefinition((int) ($attrs['version'] ?? 1)),
            'revision' => 1,
            'published_at' => null,
            'published_by' => null,
        ];
    }

    /**
     * Version publiée ; met à jour le questionnaire parent (published_version_id, status active).
     */
    public function published(): static
    {
        return $this
            ->state(fn (array $attrs) => [
                'status' => VersionStatus::Published,
                'published_at' => now(),
            ])
            ->afterCreating(function (SurveyVersion $version) {
                $survey = $version->survey;
                if ($survey && $survey->published_version_id === null) {
                    $survey->forceFill([
                        'published_version_id' => $version->id,
                        'current_version_id' => $survey->current_version_id ?? $version->id,
                        'status' => SurveyStatus::Active,
                    ])->save();
                }
            });
    }

    public function archived(): static
    {
        return $this->state([
            'status' => VersionStatus::Archived,
            'published_at' => now()->subDays(10),
        ]);
    }

    /**
     * Définition complète du questionnaire MunaGo (fixture du contrat).
     */
    public function munago(): static
    {
        return $this->state(fn (array $attrs) => [
            'definition' => self::munagoDefinition(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function munagoDefinition(): array
    {
        if (self::$munagoCache === null) {
            $path = base_path(self::MUNAGO_FIXTURE);
            if (! is_file($path)) {
                throw new \RuntimeException("Fixture MunaGo introuvable : {$path}");
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Fixture MunaGo invalide (JSON non objet).');
            }
            self::$munagoCache = $decoded;
        }

        return self::$munagoCache;
    }

    /**
     * Petit formulaire DFS v1 valide pour les tests génériques.
     *
     * @return array<string, mixed>
     */
    public static function minimalDefinition(int $version = 1): array
    {
        return [
            'dfs_version' => '1.0',
            'id' => (string) Str::uuid(),
            'version' => $version,
            'title' => ['fr' => 'Formulaire de test'],
            'settings' => [
                'languages' => ['fr'],
                'default_language' => 'fr',
                'quotas' => [],
                'kpis' => [],
                'geo' => ['capture' => 'none', 'required' => false],
                'pagination' => 'section',
                'allow_public_link' => false,
                'enumerator_can_edit_after_submit' => false,
                'duplicate_keys' => [],
                'followup_contact_keys' => [],
            ],
            'choice_lists' => [
                'oui_non' => [
                    ['name' => 'oui', 'label' => ['fr' => 'Oui']],
                    ['name' => 'non', 'label' => ['fr' => 'Non']],
                ],
            ],
            'sections' => [
                [
                    'key' => 'S1',
                    'label' => ['fr' => 'Section 1'],
                    'items' => [
                        ['key' => 'consent', 'type' => 'select_one', 'label' => ['fr' => 'Consentement ?'], 'required' => true, 'choices' => 'oui_non'],
                        ['key' => 'stop_consent', 'type' => 'stop', 'label' => ['fr' => 'Fin'], 'message' => ['fr' => 'Merci.'], 'relevant' => ['==' => [['var' => 'consent'], 'non']]],
                        ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge'], 'required' => false],
                        ['key' => 'commentaire', 'type' => 'text', 'label' => ['fr' => 'Commentaire'], 'appearance' => 'multiline'],
                    ],
                ],
            ],
            'follow_up_stages' => [],
        ];
    }
}
