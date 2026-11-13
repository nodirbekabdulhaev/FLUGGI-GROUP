<?php

namespace Tests\Feature\Sales;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Role;
use App\Services\People\RoleService;
use App\Support\Permissions;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** КП, договоры и оплаты оформляют только РОП и CEO; менеджер продаж их только просматривает. */
class RopOnlyManagementTest extends SalesTestCase
{
    private function roleCodes(string $roleCode): array
    {
        return DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.code', $roleCode)->pluck('rp.scope', 'p.code')->all();
    }

    private function proposalBody(string $dealId): array
    {
        return ['deal_id' => $dealId, 'title' => 'SMM', 'currency' => 'UZS', 'items' => [['description' => 'SMM', 'quantity' => 1, 'unit_price' => '9000000']]];
    }

    public function test_manager_gets_403_on_every_management_action_and_rop_succeeds(): void
    {
        $deal = $this->deal();
        $kpBody = $this->proposalBody($deal->id);

        // КП: создать, изменить, отправить, утвердить, принять — 403 менеджеру
        $this->actingAsUser($this->manager)->postJson('/sales/proposals', $kpBody)->assertForbidden();
        $this->actingAsUser($this->rop)->postJson('/sales/proposals', $kpBody)->assertOk();
        $kp = Proposal::firstOrFail();
        $this->actingAsUser($this->manager);
        $this->putJson("/sales/proposals/{$kp->id}", $kpBody)->assertForbidden();
        foreach (['submit-approval', 'approve', 'send', 'accept', 'reject'] as $action) {
            $this->postJson("/sales/proposals/{$kp->id}/$action")->assertForbidden();
        }
        $this->assertSame('DRAFT', $kp->fresh()->status);
        $this->actingAsUser($this->rop);
        $this->putJson("/sales/proposals/{$kp->id}", $kpBody)->assertOk();
        $this->postJson("/sales/proposals/{$kp->id}/send")->assertOk();
        $this->postJson("/sales/proposals/{$kp->id}/accept")->assertOk();

        // Договор: создать, изменить, подписать — 403 менеджеру
        $contractBody = ['deal_id' => $deal->id, 'proposal_id' => $kp->id, 'contract_date' => '2026-10-06', 'amount' => '9000000', 'currency' => 'UZS'];
        $this->actingAsUser($this->manager)->postJson('/sales/contracts', $contractBody)->assertForbidden();
        $this->actingAsUser($this->rop)->postJson('/sales/contracts', $contractBody)->assertOk();
        $c = Contract::firstOrFail();
        $this->actingAsUser($this->manager);
        $this->patchJson("/sales/contracts/{$c->id}", ['amount' => '1'])->assertForbidden();
        $this->postJson("/sales/contracts/{$c->id}/sign")->assertForbidden();
        $this->actingAsUser($this->rop);
        $this->patchJson("/sales/contracts/{$c->id}", ['amount' => '9000000', 'comment' => 'ok'])->assertOk();
        $this->postJson("/sales/contracts/{$c->id}/sign")->assertOk();

        // Оплата: внести, изменить, подтвердить, вернуть — 403 менеджеру
        $payBody = ['deal_id' => $deal->id, 'contract_id' => $c->id, 'amount' => '9000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'BANK'];
        $this->actingAsUser($this->manager)->postJson('/finance/payments', $payBody)->assertForbidden();
        $this->actingAsUser($this->rop)->postJson('/finance/payments', $payBody)->assertOk();
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->manager);
        $this->patchJson("/finance/payments/{$p->id}", ['amount' => '1'])->assertForbidden();
        $this->postJson("/finance/payments/{$p->id}/confirm")->assertForbidden();
        $this->assertSame('PENDING', $p->fresh()->status);
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $this->actingAsUser($this->manager)->postJson("/finance/payments/{$p->id}/refund", ['amount' => '1000', 'method' => 'CASH', 'comment' => 'x'])->assertForbidden();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/refund", ['amount' => '1000', 'method' => 'CASH', 'comment' => 'Возврат'])->assertOk();

        // CEO тоже управляет
        $other = $this->deal();
        $this->actingAsUser($this->ceo)->postJson('/sales/proposals', $this->proposalBody($other->id))->assertOk();

        // Менеджер сохраняет просмотр
        $this->actingAsUser($this->manager);
        foreach (['/sales/proposals', '/sales/contracts', '/finance/payments', "/sales/deals/{$deal->id}"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->getJson("/sales/proposals/{$kp->id}/versions")->assertOk();
        $this->getJson("/sales/deals/{$deal->id}/money")->assertOk();
        $this->get("/documents/proposals/{$kp->id}?format=html")->assertOk();
        $this->get("/documents/contracts/{$c->id}?format=html")->assertOk();
    }

    public function test_rights_granted_to_manager_directly_in_db_are_ignored(): void
    {
        $deal = $this->deal();
        $manager = Role::where('code', 'MANAGER')->firstOrFail();
        $ids = Permission::whereIn('code', Permissions::ROP_UP)->pluck('id');
        DB::table('role_permissions')->insertOrIgnore($ids->map(fn ($id) => ['role_id' => $manager->id, 'permission_id' => $id, 'scope' => 'ALL'])->all());

        $access = $this->as($this->manager);
        foreach (Permissions::ROP_UP as $code) {
            $this->assertFalse($access->can($code), $code);
        }
        $this->actingAsUser($this->manager)->postJson('/sales/proposals', $this->proposalBody($deal->id))->assertForbidden();
        $this->postJson('/finance/payments', ['deal_id' => $deal->id, 'amount' => '1', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'BANK'])->assertForbidden();
        $this->assertSame(0, Proposal::count() + Payment::count());
    }

    public function test_role_service_drops_rop_up_codes_for_manager_but_keeps_them_for_rop(): void
    {
        $this->as($this->ceo);
        $manager = Role::where('code', 'MANAGER')->firstOrFail();
        RoleService::updatePermissions($manager->id, $this->roleCodes('MANAGER') + array_fill_keys(Permissions::ROP_UP, 'OWN'));
        $saved = $this->roleCodes('MANAGER');
        $this->assertSame([], array_values(array_intersect(Permissions::ROP_UP, array_keys($saved))));
        foreach (['proposal.read', 'contract.read', 'payment.read', 'deal.update'] as $code) {
            $this->assertArrayHasKey($code, $saved);
        }

        // Другие роли (кроме РОП и CEO) тоже не получают эти права
        $pm = Role::where('code', 'PROJECT_MANAGER')->firstOrFail();
        RoleService::updatePermissions($pm->id, $this->roleCodes('PROJECT_MANAGER') + ['payment.confirm' => 'ALL']);
        $this->assertArrayNotHasKey('payment.confirm', $this->roleCodes('PROJECT_MANAGER'));

        $rop = Role::where('code', 'ROP')->firstOrFail();
        RoleService::updatePermissions($rop->id, $this->roleCodes('ROP'));
        $this->assertSame([], array_values(array_diff(Permissions::ROP_UP, array_keys($this->roleCodes('ROP')))));

        // Экран ролей не предлагает менеджеру эти права, у РОП — предлагает
        $this->actingAsUser($this->ceo);
        $this->get("/settings/roles?role={$manager->id}")->assertOk()->assertDontSee('permissions[proposal.create]', false)
            ->assertDontSee('permissions[payment.confirm]', false)->assertSee('permissions[proposal.read]', false);
        $this->get("/settings/roles?role={$rop->id}")->assertOk()->assertSee('permissions[proposal.create]', false)->assertSee('permissions[payment.confirm]', false);
    }

    public function test_seeder_strips_rop_up_from_existing_manager_role_and_rop_keeps_them(): void
    {
        $manager = Role::where('code', 'MANAGER')->firstOrFail();
        $ids = Permission::whereIn('code', Permissions::ROP_UP)->pluck('id');
        DB::table('role_permissions')->insertOrIgnore($ids->map(fn ($id) => ['role_id' => $manager->id, 'permission_id' => $id, 'scope' => 'OWN'])->all());
        $this->assertNotEmpty(array_intersect(Permissions::ROP_UP, array_keys($this->roleCodes('MANAGER'))));

        $this->seed(RolesSeeder::class);

        $this->assertSame([], array_values(array_intersect(Permissions::ROP_UP, array_keys($this->roleCodes('MANAGER')))));
        $this->assertArrayHasKey('proposal.read', $this->roleCodes('MANAGER'));
        $this->assertSame([], array_values(array_diff(Permissions::ROP_UP, array_keys($this->roleCodes('ROP')))));
        $this->assertSame([], array_values(array_diff(Permissions::ROP_UP, array_keys($this->roleCodes('CEO')))));
    }

    public function test_manager_cannot_attach_or_delete_contract_files(): void
    {
        Storage::fake('local');
        $deal = $this->deal();
        [, $c] = $this->readyToPay($deal);
        $pdf = fn () => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF");
        $this->actingAsUser($this->manager)->postJson('/files', ['contract_id' => $c->id, 'category' => 'CONTRACT', 'file' => $pdf()])->assertForbidden();
        $id = $this->actingAsUser($this->rop)->postJson('/files', ['contract_id' => $c->id, 'category' => 'CONTRACT', 'file' => $pdf()])->assertOk()->json('id');
        $this->actingAsUser($this->manager)->deleteJson("/files/{$id}")->assertForbidden();
        // Файлы самой сделки менеджер по-прежнему загружает
        $this->postJson('/files', ['deal_id' => $deal->id, 'file' => $pdf()])->assertOk();
    }
}
