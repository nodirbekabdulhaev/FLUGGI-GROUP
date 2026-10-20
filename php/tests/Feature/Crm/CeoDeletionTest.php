<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\People\RoleService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Удаление лидов, клиентов и проектов — только CEO, даже если право выдать другой роли. */
class CeoDeletionTest extends TestCase
{
    private User $ceo;

    private User $rop;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        $this->rop = $this->user('ROP');
        $team = $this->team($this->rop);
        $this->rop->update(['team_id' => $team->id]);
        $this->manager = $this->user('MANAGER', $team);
    }

    private function lead(): Lead
    {
        return Lead::create([
            'title' => 'Сайт для кафе', 'source_id' => LeadSource::value('id'), 'owner_id' => $this->manager->id,
            'team_id' => $this->manager->team_id, 'stage_id' => DealStage::where('code', 'NEW')->value('id'), 'created_by_id' => $this->manager->id,
        ]);
    }

    /** Клиент со сделкой, лидом, проектом и задачей. */
    private function clientWithWork(): array
    {
        $client = Client::create(['name' => 'ООО Тест', 'owner_id' => $this->manager->id, 'team_id' => $this->manager->team_id]);
        $deal = Deal::create([
            'title' => 'SMM', 'client_id' => $client->id, 'owner_id' => $this->manager->id, 'team_id' => $this->manager->team_id,
            'amount' => 1000000, 'amount_uzs' => 1000000, 'stage_id' => DealStage::where('code', 'NEED_DEFINED')->value('id'), 'created_by_id' => $this->manager->id,
        ]);
        $lead = $this->lead();
        $lead->update(['client_id' => $client->id]);
        $project = Project::create([
            'name' => 'SMM', 'client_id' => $client->id, 'deal_id' => $deal->id, 'price' => 1000000, 'price_uzs' => 1000000,
            'rop_id' => $this->rop->id, 'manager_id' => $this->manager->id, 'team_id' => $this->manager->team_id,
        ]);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Контент-план', 'assignee_id' => $this->rop->id, 'creator_id' => $this->rop->id]);

        return [$client, $deal, $lead, $project, $task];
    }

    public function test_only_ceo_has_delete_permissions_even_if_granted(): void
    {
        $rop = Role::where('code', 'ROP')->first();
        // Попытка выдать РОП права удаления через настройки ролей — права отбрасываются
        $current = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $rop->id)->pluck('rp.scope', 'p.code')->all();
        $this->as($this->ceo);
        RoleService::updatePermissions($rop->id, $current + ['lead.delete' => 'ALL', 'client.delete' => 'ALL', 'project.delete' => 'ALL']);
        $granted = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $rop->id)->whereIn('p.code', ['lead.delete', 'client.delete', 'project.delete'])->count();
        $this->assertSame(0, $granted);

        // Даже если право попало в базу напрямую — сервер его не принимает
        $permId = DB::table('permissions')->where('code', 'lead.delete')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $rop->id, 'permission_id' => $permId, 'scope' => 'ALL']);
        $lead = $this->lead();
        $this->actingAsUser($this->rop)->delete("/sales/leads/{$lead->id}")->assertForbidden();
        $this->assertNotNull($lead->fresh());
        $this->assertFalse($this->as($this->rop)->can('lead.delete'));
    }

    public function test_others_cannot_delete_and_see_no_buttons(): void
    {
        [$client, , $lead, $project] = $this->clientWithWork();
        foreach ([$this->rop, $this->manager] as $user) {
            $this->actingAsUser($user)->delete("/sales/leads/{$lead->id}")->assertForbidden();
            $this->actingAsUser($user)->delete("/clients/{$client->id}")->assertForbidden();
            $this->actingAsUser($user)->delete("/projects/{$project->id}")->assertForbidden();
        }
        $this->actingAsUser($this->rop)->get("/clients/{$client->id}")->assertOk()->assertDontSee('Удалить клиента');
        $this->assertNotNull(Client::find($client->id));
        $this->assertNotNull(Project::find($project->id));
    }

    public function test_ceo_deletes_lead_including_converted(): void
    {
        $lead = $this->lead();
        $lead->update(['status' => 'CONVERTED']);
        $this->actingAsUser($this->ceo)->delete("/sales/leads/{$lead->id}")->assertRedirect(route('crm.leads.index'));
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertTrue(AuditLog::where('action', 'lead.delete')->where('entity_id', $lead->id)->exists());
    }

    public function test_ceo_deletes_client_with_leads_deals_and_projects(): void
    {
        [$client, $deal, $lead, $project, $task] = $this->clientWithWork();
        $this->actingAsUser($this->ceo)->get("/clients/{$client->id}")->assertOk()->assertSee('Удалить клиента');
        $this->delete("/clients/{$client->id}")->assertRedirect(route('crm.clients.index'));
        foreach (['clients' => $client, 'deals' => $deal, 'leads' => $lead, 'projects' => $project, 'tasks' => $task] as $table => $row) {
            $this->assertSoftDeleted($table, ['id' => $row->id]);
        }
        $log = AuditLog::where('action', 'client.delete')->where('entity_id', $client->id)->first();
        $this->assertEquals(['leads' => 1, 'deals' => 1, 'projects' => 1], $log->changes['cascade']['new']);
        // Удалённый клиент не открывается и не попадает в список
        $this->get("/clients/{$client->id}")->assertNotFound();
        $this->get('/clients')->assertOk()->assertDontSee('ООО Тест');
    }

    public function test_ceo_deletes_project_with_tasks_but_deal_stays(): void
    {
        [, $deal, , $project, $task] = $this->clientWithWork();
        $this->actingAsUser($this->ceo)->get("/projects/{$project->id}")->assertOk()->assertSee(t('projects.deleteConfirm', ['name' => $project->name]), false);
        $this->delete("/projects/{$project->id}")->assertRedirect(route('projects.index', 'all'));
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertNotSoftDeleted('deals', ['id' => $deal->id]);
        $this->get("/projects/{$project->id}")->assertNotFound();
        // Карточка сделки по-прежнему открывается
        $this->get("/sales/deals/{$deal->id}")->assertOk();
    }
}
