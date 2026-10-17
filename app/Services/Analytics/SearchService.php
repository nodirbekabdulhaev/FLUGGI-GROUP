<?php

namespace App\Services\Analytics;

use App\Auth\Access;
use App\Models\Contract;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Support\Format;

/**
 * Глобальный поиск (ТЗ §44): клиенты, лиды, сделки, проекты, договоры, задачи, сотрудники.
 * Каждая сущность ищется только в пределах прав пользователя; без права — пропускается.
 */
final class SearchService
{
    private const LIMIT = 5;

    public const ORDER = ['client', 'lead', 'deal', 'project', 'contract', 'task', 'user'];

    /** «D-00012», «ДГ-12», «12» → 12; иначе null. */
    public static function parseNumber(string $q): ?int
    {
        return preg_match('/^(?:[a-zа-яё]{1,3}\s*-?\s*)?0*(\d{1,9})$/iu', trim($q), $m) ? (int) $m[1] : null;
    }

    private static function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }

    /** @return list<array{kind:string,id:string,title:string,subtitle:?string,link:string}> */
    public static function search(Access $a, string $q): array
    {
        $q = trim($q);
        $n = self::parseNumber($q);
        $like = self::like($q);
        $phone = preg_replace('/[^\d+]/', '', $q);
        $phoneLike = strlen($phone) >= 5 ? self::like($phone) : null;
        $orText = function ($w, array $cols) use ($like, $phoneLike, $n) {
            foreach ($cols as $c) {
                $w->orWhere($c, 'like', $like);
            }
            if ($phoneLike) {
                $w->orWhere('phone', 'like', $phoneLike);
            }
            if ($n !== null) {
                $w->orWhere('number', $n);
            }
        };
        $hits = [];

        if ($a->can('client.read')) {
            foreach (CrmAccess::clients()->where(fn ($w) => $orText($w, ['name', 'email', 'telegram']))->orderByDesc('updated_at')->limit(self::LIMIT)->get() as $r) {
                $hits[] = ['kind' => 'client', 'id' => $r->id, 'title' => $r->name,
                    'subtitle' => implode(' · ', array_filter([Format::code('C', $r->number), $r->phone])), 'link' => '/clients/'.$r->id];
            }
        }
        if ($a->can('lead.read')) {
            foreach (CrmAccess::leads()->where(fn ($w) => $orText($w, ['title', 'contact_name', 'company_name', 'email', 'telegram']))->orderByDesc('updated_at')->limit(self::LIMIT)->get() as $r) {
                $hits[] = ['kind' => 'lead', 'id' => $r->id, 'title' => $r->title,
                    'subtitle' => implode(' · ', array_filter([Format::code('L', $r->number), $r->contact_name, $r->phone])), 'link' => '/sales/leads/'.$r->id];
            }
        }
        if ($a->can('deal.read')) {
            $rows = CrmAccess::deals()->with('client:id,name')
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like))
                    ->when($n !== null, fn ($x) => $x->orWhere('number', $n)))
                ->orderByDesc('updated_at')->limit(self::LIMIT)->get();
            foreach ($rows as $r) {
                $hits[] = ['kind' => 'deal', 'id' => $r->id, 'title' => $r->title, 'subtitle' => Format::code('D', $r->number).' · '.$r->client?->name, 'link' => '/sales/deals/'.$r->id];
            }
        }
        if ($a->can('project.read')) {
            $rows = ProjectScope::projects($a)->with('client:id,name')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like))
                    ->when($n !== null, fn ($x) => $x->orWhere('number', $n)))
                ->orderByDesc('updated_at')->limit(self::LIMIT)->get();
            foreach ($rows as $r) {
                $hits[] = ['kind' => 'project', 'id' => $r->id, 'title' => $r->name, 'subtitle' => Format::code('P', $r->number).' · '.$r->client?->name, 'link' => '/projects/'.$r->id];
            }
        }
        if ($a->can('contract.read')) {
            $rows = Contract::with('deal.client:id,name')->whereIn('deal_id', CrmAccess::deals('contract.read')->select('deals.id'))
                ->where(fn ($w) => $w->when($n !== null, fn ($x) => $x->orWhere('number', $n))
                    ->orWhereHas('deal.client', fn ($c) => $c->where('name', 'like', $like)))
                ->orderByDesc('created_at')->limit(self::LIMIT)->get();
            foreach ($rows as $r) {
                $hits[] = ['kind' => 'contract', 'id' => $r->id, 'title' => t('search.contract', ['number' => Format::code('ДГ', $r->number)]),
                    'subtitle' => $r->deal?->client?->name, 'link' => '/sales/deals/'.$r->deal_id];
            }
        }
        if ($a->can('task.read')) {
            $rows = ProjectScope::tasks($a)->with('project:id,name')
                ->where(fn ($w) => $w->where('title', 'like', $like)->when($n !== null, fn ($x) => $x->orWhere('number', $n)))
                ->orderByDesc('updated_at')->limit(self::LIMIT)->get();
            foreach ($rows as $r) {
                $hits[] = ['kind' => 'task', 'id' => $r->id, 'title' => $r->title, 'subtitle' => Format::code('T', $r->number).' · '.$r->project?->name,
                    'link' => '/projects/'.$r->project_id.'?task='.$r->id];
            }
        }
        if ($a->can('employee.read')) {
            $users = $a->restrict(User::with('role'), 'employee.read',
                fn ($w, $uid) => $w->where('id', $uid),
                fn ($w, $teamIds, $uid) => $w->whereIn('team_id', $teamIds ?: ['-'])->orWhere('id', $uid))
                ->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like))
                ->limit(self::LIMIT)->get();
            foreach ($users as $r) {
                $hits[] = ['kind' => 'user', 'id' => $r->id, 'title' => $r->full_name, 'subtitle' => t('roles.'.$r->role->code).' · '.$r->email,
                    'link' => '/team/employees?q='.rawurlencode($r->email)];
            }
        }

        return $hits;
    }
}
