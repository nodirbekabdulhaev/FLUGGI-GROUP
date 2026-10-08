<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;
use App\Services\ScheduleService;

class ExtendLessons extends Command
{
    use RunsPerOrganization;

    protected $signature = 'lessons:extend';
    protected $description = 'Генерация занятий вперёд по активным расписаниям (cron ночью)';

    public function handle(): int
    {
        return $this->eachOrganization(function () {
            $svc = app(ScheduleService::class);
            $this->info('lessons created: '.$svc->extendAll());

            // surface timetable clashes in the notification centre (spec §60)
            foreach ($svc->conflictsAhead() as $c) {
                $what = ['teacher' => 'преподавателя '.($c['a']->teacher?->full_name), 'room' => 'аудитории '.($c['a']->room?->name), 'group' => 'группы '.($c['a']->group?->name)][$c['type']];
                app(NotificationService::class)->toStaff('schedule.manage', 'system', 'Конфликт расписания',
                    "{$c['date']}: пересечение {$what}\n{$c['a']->group?->name} ".ftime($c['a']->start_time).' и '.$c['b']->group?->name.' '.ftime($c['b']->start_time),
                    '/lessons?date='.$c['date'], null, 'conflict:'.$c['a']->id.':'.$c['b']->id.':'.$c['type']);
            }
        });
    }
}
