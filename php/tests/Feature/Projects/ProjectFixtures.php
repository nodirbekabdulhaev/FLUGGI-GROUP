<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Direction;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

/** Данные для тестов проектов, финансов и людей: отдел, сотрудники, проект сделки. */
trait ProjectFixtures
{
    protected User $ceo;

    protected User $rop;

    protected User $manager;

    protected User $otherManager;

    protected User $executor;

    protected Team $team1;

    protected Team $team2;

    protected function setUpPeople(): void
    {
        $this->ceo = $this->user('CEO', attrs: ['full_name' => 'Ceo Boss']);
        $this->rop = $this->user('ROP', attrs: ['full_name' => 'Rop One']);
        $this->team1 = $this->team($this->rop);
        $this->rop->update(['team_id' => $this->team1->id]);
        $this->manager = $this->user('MANAGER', $this->team1, ['full_name' => 'Manager One']);
        $rop2 = $this->user('ROP', attrs: ['full_name' => 'Rop Two']);
        $this->team2 = $this->team($rop2);
        $this->otherManager = $this->user('MANAGER', $this->team2, ['full_name' => 'Manager Two']);
        $this->executor = $this->user('EXECUTOR', attrs: ['full_name' => 'Executor One'], employee: ['specialty' => 'SMM']);
    }

    protected function project(array $attrs = [], ?string $directionCode = null): Project
    {
        $client = Client::create(['name' => 'Aziz Market', 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id]);
        $deal = Deal::create([
            'title' => 'SMM', 'client_id' => $client->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'amount' => '6000000', 'amount_uzs' => '6000000', 'stage_id' => DealStage::where('code', 'PAID')->value('id'),
            'status' => 'WON', 'won_at' => now(), 'created_by_id' => $this->manager->id,
        ]);
        $p = Project::create($attrs + [
            'name' => 'SMM для Aziz', 'client_id' => $client->id, 'deal_id' => $deal->id, 'price' => '6000000', 'price_uzs' => '6000000',
            'rop_id' => $this->rop->id, 'manager_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'direction_id' => $directionCode ? Direction::where('code', $directionCode)->value('id') : null,
        ]);

        return $p->refresh();
    }
}
