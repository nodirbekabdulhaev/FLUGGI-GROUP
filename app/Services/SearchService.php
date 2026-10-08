<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\Student;
use App\Models\User;

/** Global search: name, phone, student/lead ID, Telegram, group name. */
class SearchService
{
    public function search(string $term, User $user, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term);
        $id = preg_match('/^#?\s*(\d{1,9})$/', $term, $m) ? (int) $m[1] : null;
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        $nameMatch = function ($q, array $cols) use ($words) {
            foreach ($words as $w) {
                $q->where(function ($qq) use ($cols, $w) {
                    foreach ($cols as $c) {
                        $qq->orWhere($c, 'like', '%'.$w.'%');
                    }
                });
            }
        };
        $phoneLike = strlen($digits) >= 3 ? '%'.$digits.'%' : null;

        $out = [];

        if ($user->hasPermission('students.view') || $user->restrictedToOwnGroups()) {
            $rows = Student::visibleTo($user)->where(function ($q) use ($nameMatch, $id, $phoneLike, $like) {
                $q->where(fn ($n) => $nameMatch($n, ['first_name', 'last_name']))
                    ->orWhere('telegram', 'like', $like);
                if ($id) {
                    $q->orWhere('id', $id);
                }
                if ($phoneLike) {
                    $q->orWhere('phone', 'like', $phoneLike);
                }
            })->limit($limit)->get();
            $out['students'] = $rows->map(fn ($s) => ['id' => $s->id, 'title' => $s->full_name, 'sub' => trim('#'.$s->id.' · '.$s->phone), 'url' => route('students.show', $s)])->all();

            $out['parents'] = $user->hasPermission('students.view') ? Guardian::where(function ($q) use ($phoneLike, $like) {
                $q->where('full_name', 'like', $like);
                if ($phoneLike) {
                    $q->orWhere('phone', 'like', $phoneLike);
                }
            })->limit($limit)->get()->map(fn ($g) => ['id' => $g->id, 'title' => $g->full_name, 'sub' => $g->phone, 'url' => route('guardians.show', $g)])->all() : [];
        }

        if ($user->hasPermission('leads.view')) {
            $rows = Lead::visibleTo($user)->where(function ($q) use ($nameMatch, $id, $phoneLike, $like) {
                $q->where(fn ($n) => $nameMatch($n, ['first_name', 'last_name']))
                    ->orWhere('telegram', 'like', $like)->orWhere('instagram', 'like', $like);
                if ($id) {
                    $q->orWhere('id', $id);
                }
                if ($phoneLike) {
                    $q->orWhere('phone', 'like', $phoneLike);
                }
            })->limit($limit)->get();
            $out['leads'] = $rows->map(fn ($l) => ['id' => $l->id, 'title' => $l->full_name, 'sub' => trim('#'.$l->id.' · '.$l->phone), 'url' => route('leads.show', $l)])->all();
        }

        if ($user->hasPermission('groups.view') || $user->hasPermission('groups.view_own')) {
            $out['groups'] = Group::visibleTo($user)->where('name', 'like', $like)->limit($limit)->get()
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->name, 'sub' => Group::STATUSES[$g->status] ?? '', 'url' => route('groups.show', $g)])->all();
        }

        return array_filter($out);
    }
}
