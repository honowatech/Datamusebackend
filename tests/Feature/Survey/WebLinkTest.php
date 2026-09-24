<?php

namespace Tests\Feature\Survey;

use App\Enums\SurveyStatus;
use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\PublicLink;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * Lien web par défaut d'un questionnaire (`/surveys/{id}/web-link`, onglet « Lien Web »).
 */
class WebLinkTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load(['limit' => 1]);
        $this->fx->survey->forceFill(['status' => SurveyStatus::Active])->save();

        $this->analyst = $this->fx->owner;
        ProjectMember::factory()->analyste()->create(['project_id' => $this->fx->project->id, 'user_id' => $this->analyst->id]);

        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->fx->project->id, 'user_id' => $this->supervisor->id]);
    }

    private function url(?Survey $survey = null): string
    {
        return '/api/surveys/'.($survey ?? $this->fx->survey)->id.'/web-link';
    }

    public function test_a_new_survey_gets_its_web_link(): void
    {
        $project = SurveyProject::factory()->create(['owner_id' => $this->analyst->id]);
        ProjectMember::factory()->analyste()->create(['project_id' => $project->id, 'user_id' => $this->analyst->id]);

        $id = $this->actingAs($this->analyst, 'sanctum')
            ->postJson("/api/projects/{$project->id}/surveys", ['title' => 'Nouvelle enquête'])
            ->assertCreated()->json('data.id');

        $links = PublicLink::query()->where('survey_id', $id)->get();
        $this->assertCount(1, $links);
        $this->assertTrue($links[0]->is_default);
        $this->assertTrue($links[0]->is_active);

        // Le duplicata a le sien, distinct.
        $copyId = $this->actingAs($this->analyst, 'sanctum')->postJson("/api/surveys/{$id}/duplicate")->assertCreated()->json('data.id');
        $copyLink = PublicLink::query()->where('survey_id', $copyId)->where('is_default', true)->firstOrFail();
        $this->assertNotSame($links[0]->token, $copyLink->token);
    }

    public function test_show_creates_the_link_once_and_returns_the_same_one(): void
    {
        $first = $this->actingAs($this->supervisor)->getJson($this->url())->assertOk()->json('data');
        $again = $this->actingAs($this->analyst)->getJson($this->url())->assertOk()->json('data');

        $this->assertTrue($first['is_default']);
        $this->assertSame('open', $first['state']);
        $this->assertStringEndsWith('/s?token='.$first['token'], $first['url']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, PublicLink::query()->where('survey_id', $this->fx->survey->id)->count());

        // Le lien sert le questionnaire publié.
        $this->getJson('/api/public/surveys/'.$first['token'])->assertOk();
    }

    public function test_regenerate_retires_the_old_link(): void
    {
        $old = $this->actingAs($this->analyst)->getJson($this->url())->json('data');

        $new = $this->actingAs($this->analyst)->postJson($this->url().'/regenerate')->assertCreated()->json('data');

        $this->assertNotSame($old['token'], $new['token']);
        $this->assertTrue($new['is_default']);
        $this->assertSame($new['id'], $this->actingAs($this->analyst)->getJson($this->url())->json('data.id'));

        $this->getJson('/api/public/surveys/'.$old['token'])->assertStatus(410)->assertJsonPath('reason', 'inactive');
        $this->getJson('/api/public/surveys/'.$new['token'])->assertOk();
        $this->assertSame(1, PublicLink::query()->where('survey_id', $this->fx->survey->id)->where('is_default', true)->count());
    }

    public function test_only_analysts_regenerate_and_strangers_see_nothing(): void
    {
        $this->actingAs($this->supervisor)->postJson($this->url().'/regenerate')->assertForbidden();

        $stranger = User::factory()->create(['role' => UserRole::Analyste]);
        $this->actingAs($stranger)->getJson($this->url())->assertForbidden();
        $this->assertSame(0, PublicLink::query()->where('survey_id', $this->fx->survey->id)->count());
    }
}
