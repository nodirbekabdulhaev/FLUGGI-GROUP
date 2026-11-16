<?php

namespace App\Services\Integrations;

use App\Models\WebsiteLead;
use Illuminate\Database\QueryException;

/**
 * Приём заявок с сайта fluggi.uz: один лид на external_id (повторная отправка — 409 с тем же id),
 * дальше — общие правила приёма внешних заявок (поиск повторной заявки по телефону, ответственный, скоринг).
 */
final class WebsiteLeads
{
    public const TYPES = ['contact', 'callback', 'solution', 'service', 'demo', 'vacancy'];

    private const TYPE_LABELS = ['contact' => 'Общая форма', 'callback' => 'Перезвоните мне', 'solution' => 'Интерес к решению', 'service' => 'Интерес к услуге',
        'demo' => 'Демо', 'vacancy' => 'Отклик на вакансию'];

    /** @return array{id:?string,duplicate:bool,existing:bool} existing — такая заявка уже принималась (external_id) */
    public static function receive(array $in): array
    {
        if ($row = WebsiteLead::where('external_id', $in['external_id'])->first()) {
            return ['id' => $row->lead_id, 'duplicate' => false, 'existing' => true];
        }
        try {
            $row = WebsiteLead::create(['external_id' => $in['external_id'], 'type' => $in['type'], 'payload' => $in]);
        } catch (QueryException) {
            // Параллельный повтор: запись уже создана другим запросом
            $row = WebsiteLead::where('external_id', $in['external_id'])->first();
            if ($row) {
                return ['id' => $row->lead_id, 'duplicate' => false, 'existing' => true];
            }
            throw new \RuntimeException('website lead insert failed');
        }
        $label = self::TYPE_LABELS[$in['type']] ?? $in['type'];
        $product = $in['product']['name'] ?? null;
        $ctx = is_array($in['context'] ?? null) ? $in['context'] : [];
        $utm = fn (?array $u) => $u ? implode(', ', array_map(fn ($k, $v) => $k.'='.$v, array_keys(array_filter($u, fn ($v) => $v !== null && $v !== '')), array_filter($u, fn ($v) => $v !== null && $v !== ''))) : null;
        $comment = implode("\n", array_filter([
            $in['message'] ?? null,
            'Тип заявки: '.$label,
            $product ? 'Продукт: '.$product.(! empty($in['product']['kind']) ? ' ('.$in['product']['kind'].')' : '') : null,
            ! empty($ctx['page_url']) ? 'Страница: '.mb_substr((string) $ctx['page_url'], 0, 300) : null,
            ! empty($ctx['locale']) ? 'Язык сайта: '.$ctx['locale'] : null,
            isset($ctx['demo_seconds']) && is_numeric($ctx['demo_seconds']) ? 'Время в демо: '.(int) $ctx['demo_seconds'].' с' : null,
            $utm($in['utm'] ?? null) ? 'UTM (первое касание): '.$utm($in['utm']) : null,
            $utm($in['utm_last'] ?? null) ? 'UTM (последнее касание): '.$utm($in['utm_last']) : null,
            ! empty($in['created_at']) ? 'Отправлена с сайта: '.$in['created_at'] : null,
            'Заявка сайта № '.$in['external_id'],
        ]));
        $settings = IntakeService::settings();
        $res = IntakeService::intake([
            'title' => (($in['company'] ?? null) ?: $in['name']).' — '.($product ?: $label),
            'contactName' => $in['name'], 'companyName' => $in['company'] ?? null, 'phone' => $in['phone'], 'email' => $in['email'] ?? null,
            'comment' => $comment, 'sourceCode' => 'WEBSITE', 'serviceId' => $settings['serviceId'] ?? null,
            'ownerId' => $settings['ownerId'] ?? null, 'teamId' => $settings['teamId'] ?? null, 'channel' => 'Сайт '.($in['source'] ?? 'fluggi.uz').' · '.$label,
        ]);
        $row->update(['lead_id' => $res['leadId']]);

        return ['id' => $res['leadId'], 'duplicate' => $res['duplicate'], 'existing' => false];
    }
}
