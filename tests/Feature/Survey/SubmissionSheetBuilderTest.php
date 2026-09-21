<?php

namespace Tests\Feature\Survey;

use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Models\SurveyVersion;
use App\Services\Survey\SubmissionSheetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * F-B3 — `SubmissionSheetBuilder` : la fiche est construite avec la version de LA soumission,
 * le moteur DFS étant rejoué sur ses réponses.
 */
class SubmissionSheetBuilderTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private SubmissionSheetBuilder $builder;

    /** Fiche hors cible de la fixture (`stop_f1_enfant`). */
    private const SCREENED_OUT = '1edd5be3-cb4a-43f9-a4cb-0c6db34f666f';

    /** Fiche avec acompte, photo et trois étapes de suivi complétées (j7_retire = oui). */
    private const WITH_DEPOSIT = '5237bc92-dd65-482f-ba2b-a385a448239a';

    /** Fiche avec acompte dont j7_retire = non : j14 est `skipped`. */
    private const DEPOSIT_NOT_PICKED = 'ac22f891-5d58-40c0-8da1-1a3632a4dde4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load();
        $this->builder = app(SubmissionSheetBuilder::class);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>> clé de question => item (dernière occurrence)
     */
    private function itemsByKey(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $out[$item['key']] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, bool>
     */
    private function sectionsAsked(array $sections): array
    {
        return collect($sections)->mapWithKeys(fn (array $s) => [$s['key'] => $s['asked']])->all();
    }

    // ------------------------------------------------------------------ fiche complète

    public function test_a_complete_sheet_follows_the_questionnaire_order_and_renders_values(): void
    {
        $sheet = $this->builder->build($this->fx->submission(self::WITH_DEPOSIT), includePii: true);

        $this->assertSame(
            ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'T'],
            array_column($sheet['sections'], 'key'),
            "les sections sortent dans l'ordre du document",
        );
        $this->assertSame(1, $sheet['survey']['version']);
        $this->assertSame('fr', $sheet['survey']['language']);
        $this->assertSame(['fr', 'en'], $sheet['survey']['languages']);
        $this->assertNull($sheet['stop']);

        $items = $this->itemsByKey($sheet['sections']);

        // Les `note` sont omises, les `calculate` présents avec leur valeur lisible.
        $this->assertArrayNotHasKey('a_consigne_regles', $items);
        $this->assertArrayNotHasKey('e_script', $items);
        $this->assertArrayHasKey('nb_signaux', $items);
        $this->assertSame('calculate', $items['nb_signaux']['type']);

        // Libellé résolu, choix rendu par son libellé.
        $this->assertSame('Acceptez-vous de participer ?', $items['consentement']['label']);
        $this->assertTrue($items['consentement']['asked']);
        $this->assertTrue($items['consentement']['answered']);
        $this->assertSame('oui', $items['consentement']['value']);
        $this->assertSame('Oui', $items['consentement']['display']);

        // Montant : `5 000 FCFA` (plan § 2).
        $this->assertSame(5000, $items['montant_recu']['value']);
        $this->assertSame('5 000 FCFA', $items['montant_recu']['display']);

        // Date : 21/09/2026.
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', (string) $items['date_limite_retrait']['display']);

        // Média : nom du fichier + URL signée.
        $this->assertNotNull($items['photo_recu_momo']['media']);
        $this->assertSame('image/jpeg', $items['photo_recu_momo']['media']['mime']);
        $this->assertStringContainsString('/api/media/', (string) $items['photo_recu_momo']['media']['signed_url']);
        $this->assertNotNull($items['photo_recu_momo']['media']['expires_at']);
        $this->assertSame('photo_recu_momo.jpg', $items['photo_recu_momo']['display']);

        // Post-codage enquêteur : libellés, pas des codes.
        $this->assertNotNull($items['q6_freins']['codes']);
        $this->assertNotSame([], $items['q6_freins']['codes']);
        foreach ($items['q6_freins']['codes'] as $label) {
            $this->assertStringNotContainsString('_', $label, 'les codes sont résolus en libellés');
        }

        // Origine et navigation.
        $this->assertSame('mobile', $sheet['origin']['channel']);
        $this->assertStringStartsWith('Enquêteur : ', $sheet['origin']['label']);
        $this->assertNull($sheet['origin']['public_link']);
        $this->assertArrayHasKey('previous_id', $sheet['navigation']);
        $this->assertArrayHasKey('next_id', $sheet['navigation']);
    }

    public function test_an_other_answer_is_exposed_next_to_its_host_question(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);
        $answers = $submission->answers;
        $answers['lieu_enrolement'] = 'autre';
        $answers['lieu_enrolement_other'] = 'Devant la pharmacie';
        $submission->forceFill(['answers' => $answers])->save();

        $items = $this->itemsByKey($this->builder->build($submission->refresh(), includePii: true)['sections']);

        $this->assertSame('Devant la pharmacie', $items['lieu_enrolement']['other']);
        $this->assertArrayNotHasKey('lieu_enrolement_other', $items, 'un compagnon ne devient jamais une ligne');
    }

    // ------------------------------------------------------------------ hors cible

    public function test_a_screened_out_sheet_marks_the_sections_after_the_stop_as_not_asked(): void
    {
        $sheet = $this->builder->build($this->fx->submission(self::SCREENED_OUT), includePii: true);

        $this->assertNotNull($sheet['stop']);
        $this->assertSame('stop_f1_enfant', $sheet['stop']['key']);
        $this->assertStringContainsString('Remercier', $sheet['stop']['message']);

        $asked = $this->sectionsAsked($sheet['sections']);
        $this->assertTrue($asked['A']);
        $this->assertTrue($asked['B'], 'la section qui porte le STOP reste posée');
        $this->assertFalse($asked['C'], 'tout ce qui suit le STOP est « non posé »');
        $this->assertFalse($asked['T']);

        $items = $this->itemsByKey($sheet['sections']);
        $this->assertTrue($items['f1_enfant_scolarise']['asked']);
        $this->assertFalse($items['sexe']['asked'], 'une question après le STOP dans la même section');
        $this->assertFalse($items['q13_decision']['asked']);
    }

    // ------------------------------------------------------------------ données personnelles

    public function test_pii_answers_are_masked_unless_the_reader_is_an_analyst(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);

        $masked = $this->itemsByKey($this->builder->build($submission, includePii: false)['sections']);
        $this->assertTrue($masked['num_whatsapp']['pii']);
        $this->assertTrue($masked['num_whatsapp']['masked']);
        $this->assertNull($masked['num_whatsapp']['value']);
        $this->assertNull($masked['num_whatsapp']['display']);
        $this->assertTrue($masked['num_whatsapp']['answered'], 'la fiche dit qu\'une réponse existe, sans la montrer');
        $this->assertFalse($masked['montant_recu']['masked']);

        $visible = $this->itemsByKey($this->builder->build($submission, includePii: true)['sections']);
        $this->assertFalse($visible['num_whatsapp']['masked']);
        $this->assertSame('699412873', $visible['num_whatsapp']['value']);
    }

    // ------------------------------------------------------------------ suivis

    public function test_follow_up_stages_are_rendered_with_their_own_answers(): void
    {
        $sheet = $this->builder->build($this->fx->submission(self::WITH_DEPOSIT), includePii: true);

        $this->assertCount(3, $sheet['follow_ups']);
        $this->assertSame(['j4', 'j7', 'j14'], array_column($sheet['follow_ups'], 'stage_key'));

        $j4 = $sheet['follow_ups'][0];
        $this->assertSame('done', $j4['status']);
        $this->assertNotSame([], $j4['items']);
        $byKey = collect($j4['items'])->keyBy('key');
        $this->assertSame('Oui', $byKey['j4_rappel_envoye']['display']);
        $this->assertTrue($byKey['j4_rappel_envoye']['asked']);
        $this->assertArrayNotHasKey('j4_consigne', $byKey->all(), 'les notes d\'étape sont omises aussi');

        // J+14 n'est pertinente que si l'appareil a été retiré à J+7 (§ 14) : ici oui.
        $j14 = collect($sheet['follow_ups'])->firstWhere('stage_key', 'j14');
        $active = collect($j14['items'])->firstWhere('key', 'j14_active');
        $this->assertTrue($active['asked']);
        $this->assertSame('Oui', $active['display']);
    }

    public function test_a_skipped_stage_carries_no_item(): void
    {
        $sheet = $this->builder->build($this->fx->submission(self::DEPOSIT_NOT_PICKED), includePii: true);

        $j14 = collect($sheet['follow_ups'])->firstWhere('stage_key', 'j14');
        $this->assertSame('skipped', $j14['status']);
        $this->assertSame([], $j14['items']);

        $j7 = collect($sheet['follow_ups'])->firstWhere('stage_key', 'j7');
        $this->assertSame('Non', collect($j7['items'])->firstWhere('key', 'j7_retire')['display']);
    }

    // ------------------------------------------------------------------ canal public

    public function test_a_public_sheet_names_its_link(): void
    {
        $link = PublicLink::factory()->create([
            'survey_id' => $this->fx->survey->id,
            'created_by' => $this->fx->owner->id,
            'label' => 'Panel parents',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaak9Qz',
        ]);

        $source = $this->fx->submission(self::WITH_DEPOSIT);
        $public = Submission::query()->create([
            'uuid' => (string) Str::uuid(),
            'survey_id' => $this->fx->survey->id,
            'survey_version_id' => $this->fx->version->id,
            'project_id' => $this->fx->project->id,
            'enumerator_id' => null,
            'public_link_id' => $link->id,
            'channel' => SubmissionChannel::Public,
            'status' => SubmissionStatus::Submitted,
            'fiche_code' => 'DLA-BMP-99',
            'zone' => null,
            'language' => 'fr',
            'started_at' => $source->started_at,
            'ended_at' => $source->ended_at,
            'answers' => $source->answers,
            'received_at' => now(),
        ]);

        $sheet = $this->builder->build($public, includePii: true);

        $this->assertSame('public', $sheet['origin']['channel']);
        $this->assertSame('En ligne — lien « Panel parents »', $sheet['origin']['label']);
        $this->assertNull($sheet['origin']['enumerator']);
        $this->assertSame($link->id, $sheet['origin']['public_link']['id']);
        $this->assertSame('…k9Qz', $sheet['origin']['public_link']['token_hint']);
        $this->assertNull($sheet['origin']['device']['device_id']);
        $this->assertSame([], $sheet['follow_ups']);

        // Les réponses restent lisibles : la fiche publique se lit comme les autres.
        $items = $this->itemsByKey($sheet['sections']);
        $this->assertSame('Oui', $items['consentement']['display']);
    }

    // ------------------------------------------------------------------ langue

    public function test_english_labels_fall_back_to_french_when_missing(): void
    {
        $sheet = $this->builder->build($this->fx->submission(self::WITH_DEPOSIT), lang: 'en', includePii: true);
        $items = $this->itemsByKey($sheet['sections']);

        $this->assertSame('en', $sheet['survey']['language']);
        $this->assertSame('Do you agree to take part?', $items['consentement']['label']);
        $this->assertSame('Yes', $items['consentement']['display']);

        // `q6_freins` n'est pas traduit : repli sur le français (§ 9 du DFS).
        $this->assertStringStartsWith("6. Qu'est-ce qui vous", $items['q6_freins']['label']);
    }

    // ------------------------------------------------------------------ groupe répété

    public function test_a_repeated_group_produces_one_item_per_instance(): void
    {
        $version = SurveyVersion::factory()->published()->create([
            'survey_id' => $this->fx->survey->id,
            'definition' => self::repeatDefinition(),
        ]);

        $submission = Submission::query()->create([
            'uuid' => (string) Str::uuid(),
            'survey_id' => $this->fx->survey->id,
            'survey_version_id' => $version->id,
            'project_id' => $this->fx->project->id,
            'enumerator_id' => $this->fx->enumerators[1]->id,
            'channel' => SubmissionChannel::Mobile,
            'status' => SubmissionStatus::Submitted,
            'fiche_code' => 'REP-01',
            'language' => 'fr',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
            'received_at' => now(),
            'answers' => [
                'nb_enfants' => 2,
                'enfants' => [
                    ['prenom' => 'Awa', 'scolarise' => 'oui', 'classe' => 'CM2'],
                    ['prenom' => 'Ibrahim', 'scolarise' => 'non'],
                ],
            ],
        ]);

        $sheet = $this->builder->build($submission, includePii: true);
        $items = $sheet['sections'][0]['items'];

        $children = array_values(array_filter($items, fn (array $i) => $i['group'] !== null));
        $this->assertCount(6, $children, '3 questions × 2 instances');

        $this->assertSame(['enfants', 1], [$children[0]['group']['key'], $children[0]['group']['repeat_index']]);
        $this->assertSame('Awa', $children[0]['value']);
        $this->assertTrue($children[2]['asked'], "« classe » est posée quand l'enfant est scolarisé");
        $this->assertSame('CM2', $children[2]['display']);

        $this->assertSame(2, $children[3]['group']['repeat_index']);
        $this->assertSame('Ibrahim', $children[3]['value']);
        $this->assertFalse($children[5]['asked'], "« classe » n'est pas posée pour le second enfant");
        $this->assertNull($children[5]['value']);
    }

    /**
     * Questionnaire minimal avec un groupe répété (MunaGo n'en déclare aucun : sa matrice
     * `p1_composition` est un groupe d'affichage).
     *
     * @return array<string, mixed>
     */
    private static function repeatDefinition(): array
    {
        return [
            'dfs_version' => '1.0',
            'id' => '2f9a1c3d-4e5b-4a6c-8d7e-9f0a1b2c3d4e',
            'version' => 2,
            'title' => ['fr' => 'Composition du ménage'],
            'settings' => ['languages' => ['fr'], 'default_language' => 'fr'],
            'choice_lists' => [
                'oui_non' => [
                    ['name' => 'oui', 'label' => ['fr' => 'Oui']],
                    ['name' => 'non', 'label' => ['fr' => 'Non']],
                ],
            ],
            'sections' => [[
                'key' => 'M',
                'label' => ['fr' => 'Ménage'],
                'items' => [
                    ['key' => 'nb_enfants', 'type' => 'integer', 'label' => ['fr' => "Nombre d'enfants"], 'min' => 0, 'max' => 10],
                    [
                        'key' => 'enfants',
                        'type' => 'group',
                        'label' => ['fr' => 'Enfants'],
                        'repeat' => ['min' => 0, 'max' => 5],
                        'items' => [
                            ['key' => 'prenom', 'type' => 'text', 'label' => ['fr' => 'Prénom']],
                            ['key' => 'scolarise', 'type' => 'select_one', 'choices' => 'oui_non', 'label' => ['fr' => 'Scolarisé ?']],
                            [
                                'key' => 'classe',
                                'type' => 'text',
                                'label' => ['fr' => 'Classe'],
                                'relevant' => ['==' => [['var' => 'scolarise'], 'oui']],
                            ],
                        ],
                    ],
                ],
            ]],
            'follow_up_stages' => [],
        ];
    }
}
