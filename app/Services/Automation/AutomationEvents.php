<?php

namespace App\Services\Automation;

use App\Services\Notifications\NotificationEvents;
use App\Services\Notifications\Notifier;
use App\Support\Outbox;

/** Автоматизации по событиям (ТЗ §54): follow-up после проекта, достижение месячного плана. */
final class AutomationEvents
{
    public static function register(): void
    {
        // Rule 8: после завершения проекта — follow-up на 30/60/90 дней (интервалы — в настройках)
        Outbox::on('project.status_changed', function (array $e) {
            if (($e['to'] ?? null) === 'COMPLETED' && ! empty($e['projectId'])) {
                FollowUpService::createForProject($e['projectId']);
            }
        });

        // ТЗ §14: CEO (и РОП по отделу) — «Достижение месячного плана», один раз за месяц
        Outbox::on('payment.paid', function (array $e) {
            $company = ReportService::planProgress(null);
            if ($company['plan']->isGreaterThan(0) && $company['collected']->isGreaterThanOrEqualTo($company['plan'])) {
                ReminderService::sendOnce('plan.achieved', 'company:'.$company['period'], Notifier::ceoIds(), 'plan.achieved',
                    fn () => [t('automation.plan.company'), t('automation.plan.body', ['period' => $company['period'],
                        'collected' => NotificationEvents::uzs($company['collected']), 'plan' => NotificationEvents::uzs($company['plan'])])],
                    '/finance/revenue');
            }
            if (empty($e['teamId'])) {
                return;
            }
            $team = ReportService::planProgress([$e['teamId']]);
            if ($team['plan']->isGreaterThan(0) && $team['collected']->isGreaterThanOrEqualTo($team['plan'])) {
                ReminderService::sendOnce('plan.achieved', 'team:'.$e['teamId'].':'.$team['period'], [Notifier::teamHead($e['teamId'])], 'plan.achieved',
                    fn () => [t('automation.plan.team'), t('automation.plan.body', ['period' => $team['period'],
                        'collected' => NotificationEvents::uzs($team['collected']), 'plan' => NotificationEvents::uzs($team['plan'])])],
                    '/dashboard');
            }
        });
    }
}
