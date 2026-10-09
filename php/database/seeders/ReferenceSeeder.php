<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\DealStage;
use App\Models\Direction;
use App\Models\FinanceCategory;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\ProjectTemplate;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\WorkItem;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;

/**
 * Справочники CRM (ТЗ §7, §9, §39, §61, §62). Существующие записи не изменяются —
 * правки CEO в интерфейсе сохраняются. Названия на русском и узбекском.
 */
class ReferenceSeeder extends Seeder
{
    /** [код, русский, узбекский] */
    public const SERVICES = [
        ['SMM', 'SMM', 'SMM'],
        ['TARGET', 'Таргетированная реклама', 'Target reklama'],
        ['BRANDING', 'Брендинг', 'Brending'],
        ['WEBSITE', 'Сайт', 'Veb-sayt'],
        ['CRM', 'CRM-система', 'CRM tizimi'],
        ['ERP', 'ERP-система', 'ERP tizimi'],
        ['DESIGN', 'Дизайн', 'Dizayn'],
        ['PHOTO', 'Фотосъёмка', 'Fotosuratga olish'],
        ['VIDEO', 'Видеопродакшн', 'Videoprodakshn'],
        ['MARKETING', 'Маркетинг', 'Marketing'],
    ];

    public const SOURCES = [
        ['INSTAGRAM', 'Instagram', 'Instagram'],
        ['TELEGRAM', 'Telegram', 'Telegram'],
        ['WEBSITE', 'Сайт', 'Sayt'],
        ['WHATSAPP', 'WhatsApp', 'WhatsApp'],
        ['REFERRAL', 'Рекомендация', 'Tavsiya'],
        ['COLD_OUTREACH', 'Холодный контакт', 'Sovuq aloqa'],
        ['ADVERTISEMENT', 'Реклама', 'Reklama'],
        ['TARGET', 'Таргет (Meta Ads)', 'Target (Meta Ads)'],
        ['PHONE', 'Звонок', 'Qoʻngʻiroq'],
        ['OTHER', 'Другой', 'Boshqa'],
    ];

    public const LOSS_REASONS = [
        ['TOO_EXPENSIVE', 'Слишком дорого', 'Juda qimmat'],
        ['NO_BUDGET', 'Нет бюджета', 'Byudjet yoʻq'],
        ['COMPETITOR', 'Выбрал конкурента', 'Raqobatchini tanladi'],
        ['POSTPONED', 'Отложил проект', 'Loyihani keyinga qoldirdi'],
        ['NO_RESPONSE', 'Не отвечает', 'Javob bermayapti'],
        ['TERMS', 'Не подошли условия', 'Shartlar toʻgʻri kelmadi'],
        ['TIMING', 'Сроки', 'Muddatlar'],
        ['NO_NEED', 'Нет потребности', 'Ehtiyoj yoʻq'],
        ['OTHER', 'Другая причина', 'Boshqa sabab'],
    ];

    /** [код, сущность, русский, узбекский, вероятность %, цвет] */
    public const STAGES = [
        ['NEW', 'LEAD', 'Новый лид', 'Yangi lid', 5, '#71717a'],
        ['CONTACTED', 'LEAD', 'Связались', 'Bogʻlanildi', 10, '#0ea5e9'],
        ['QUALIFICATION', 'LEAD', 'Квалификация', 'Saralash', 15, '#6366f1'],
        ['MEETING_SCHEDULED', 'LEAD', 'Назначена встреча', 'Uchrashuv belgilandi', 20, '#8b5cf6'],
        ['MEETING_DONE', 'LEAD', 'Встреча проведена', 'Uchrashuv oʻtkazildi', 25, '#a855f7'],
        ['NEED_DEFINED', 'DEAL', 'Потребность определена', 'Ehtiyoj aniqlandi', 30, '#d946ef'],
        ['PROPOSAL_SENT', 'DEAL', 'КП отправлено', 'Tijorat taklifi yuborildi', 40, '#ec4899'],
        ['NEGOTIATION', 'DEAL', 'Переговоры', 'Muzokaralar', 55, '#f97316'],
        ['CONTRACT', 'DEAL', 'Договор', 'Shartnoma', 75, '#eab308'],
        ['AWAITING_PAYMENT', 'DEAL', 'Ожидаем оплату', 'Toʻlov kutilmoqda', 90, '#84cc16'],
        ['PAID', 'DEAL', 'Оплачено', 'Toʻlandi', 100, '#16a34a'],
    ];

    /** Направления бизнеса группы и их услуги. */
    public const DIRECTIONS = [
        ['IT', 'IT и разработка', ['WEBSITE', 'CRM', 'ERP']],
        ['MEDIA', 'Медиа: SMM, брендинг, продакшн', ['SMM', 'BRANDING', 'DESIGN', 'PHOTO', 'VIDEO']],
        ['MARKETING', 'Маркетинг и реклама', ['TARGET', 'MARKETING']],
    ];

    /** [задача, специальность исполнителя, начало (дней от старта), длительность (дней)] */
    public const PROJECT_TEMPLATES = [
        ['SMM', 'SMM-проект', [
            ['Контент-план', 'SMM', 0, 3], ['Съёмка', 'VIDEOGRAPHER', 3, 3], ['Монтаж', 'EDITOR', 6, 3],
            ['Дизайн', 'DESIGNER', 3, 4], ['Копирайтинг', 'COPYWRITER', 3, 4], ['Публикация', 'SMM', 8, 2],
            ['Таргет', 'TARGETOLOGIST', 8, 5], ['Отчёт', 'SMM', 28, 2],
        ]],
        ['BRANDING', 'Брендинг', [
            ['Бриф и исследование', null, 0, 3], ['Концепции логотипа', 'DESIGNER', 3, 5],
            ['Фирменный стиль', 'DESIGNER', 8, 7], ['Брендбук', 'DESIGNER', 15, 5],
            ['Передача материалов клиенту', null, 20, 1],
        ]],
        ['WEBSITE', 'Сайт', [
            ['Техническое задание', null, 0, 3], ['Прототип', 'DESIGNER', 3, 4], ['Дизайн страниц', 'DESIGNER', 7, 7],
            ['Вёрстка и разработка', 'DEVELOPER', 14, 10], ['Тексты', 'COPYWRITER', 7, 5],
            ['Тестирование и запуск', 'DEVELOPER', 24, 3],
        ]],
        ['TARGET', 'Таргетированная реклама', [
            ['Анализ аудитории', 'TARGETOLOGIST', 0, 2], ['Креативы', 'DESIGNER', 2, 3],
            ['Запуск кампаний', 'TARGETOLOGIST', 5, 1], ['Оптимизация', 'TARGETOLOGIST', 6, 20],
            ['Отчёт', 'TARGETOLOGIST', 28, 2],
        ]],
    ];

    /** [код, вид, название, счёт бухучёта, накладной расход] */
    public const FINANCE_CATEGORIES = [
        ['EXECUTOR', 'EXPENSE', 'Исполнитель', '9130', false],
        ['ADS', 'EXPENSE', 'Реклама', '9410', false],
        ['PRODUCTION', 'EXPENSE', 'Производство', '9130', false],
        ['PHOTO', 'EXPENSE', 'Фото', '9130', false],
        ['VIDEO', 'EXPENSE', 'Видео', '9130', false],
        ['DESIGN', 'EXPENSE', 'Дизайн', '9130', false],
        ['DEVELOPMENT', 'EXPENSE', 'Разработка', '9130', false],
        ['TRANSPORT', 'EXPENSE', 'Транспорт', '9420', false],
        ['MATERIALS', 'EXPENSE', 'Материалы', '9130', false],
        ['SERVICES', 'EXPENSE', 'Сервисы', '9420', false],
        ['OTHER', 'EXPENSE', 'Прочее', '9420', false],
        ['RENT', 'EXPENSE', 'Аренда', '9420', true],
        ['OFFICE', 'EXPENSE', 'Офис (связь, интернет, хозтовары)', '9420', true],
        ['TAXES', 'EXPENSE', 'Налоги и сборы', '9430', false],
        ['BANK', 'EXPENSE', 'Банковские комиссии', '9430', false],
        ['PARTNER', 'INCOME', 'Партнёрское вознаграждение', '9390', false],
        ['SUPPLIER_REFUND', 'INCOME', 'Возврат от поставщика', '9390', false],
        ['BANK_INTEREST', 'INCOME', 'Проценты банка', '9530', false],
        ['FX_GAIN', 'INCOME', 'Курсовая разница', '9540', false],
        ['OTHER_INCOME', 'INCOME', 'Прочие доходы', '9390', false],
    ];

    /** Единицы работ и базовые ставки (личные ставки — в карточке сотрудника). */
    public const WORK_ITEMS = [
        ['REEL', 'Рилс (съёмка)', 'шт', 'VIDEOGRAPHER', 10, 'USD'],
        ['COVER', 'Обложка', 'шт', 'DESIGNER', 70000, 'UZS'],
        ['CAROUSEL', 'Карусель (5 картинок)', 'шт', 'DESIGNER', 150000, 'UZS'],
        ['STORY', 'Сторис', 'шт', 'MOBILOGRAPHER', 50000, 'UZS'],
        ['BRANDFACE_REEL', 'Рилс с брендфейсом', 'шт', 'BRANDFACE', 200000, 'UZS'],
    ];

    public function run(): void
    {
        foreach (self::DIRECTIONS as $i => [$code, $name]) {
            Direction::firstOrCreate(['code' => $code], ['name' => $name, 'sort' => ($i + 1) * 10]);
        }
        foreach (self::SERVICES as $i => [$code, $ru, $uz]) {
            $dir = collect(self::DIRECTIONS)->first(fn ($d) => in_array($code, $d[2], true));
            $directionId = $dir ? Direction::where('code', $dir[0])->value('id') : null;
            $svc = Service::firstOrCreate(['code' => $code], ['name_ru' => $ru, 'name_uz' => $uz, 'sort' => $i, 'direction_id' => $directionId]);
            // Направление и узбекское название — только если их ещё нет
            $svc->update(array_filter(['direction_id' => $svc->direction_id ?? $directionId, 'name_uz' => $svc->name_uz ?? $uz]));
        }
        foreach (self::SOURCES as $i => [$code, $ru, $uz]) {
            self::uz(LeadSource::firstOrCreate(['code' => $code], ['name_ru' => $ru, 'name_uz' => $uz, 'sort' => $i]), $uz);
        }
        foreach (self::LOSS_REASONS as $i => [$code, $ru, $uz]) {
            self::uz(LossReason::firstOrCreate(['code' => $code], ['name_ru' => $ru, 'name_uz' => $uz, 'sort' => $i, 'requires_comment' => $code === 'OTHER']), $uz);
        }
        foreach (self::STAGES as $i => [$code, $entity, $ru, $uz, $probability, $color]) {
            self::uz(DealStage::firstOrCreate(['code' => $code], ['entity' => $entity, 'name_ru' => $ru, 'name_uz' => $uz, 'sort' => $i, 'probability' => $probability, 'color' => $color]), $uz);
        }

        // Правила комиссий по умолчанию (ТЗ §33–34) — один раз
        if (CommissionRule::count() === 0) {
            CommissionRule::create(['name' => 'Менеджер — 10% от оплаты', 'applies_to' => 'MANAGER', 'calc_type' => 'PERCENT_OF_PAYMENT', 'value' => 10, 'priority' => 0]);
            CommissionRule::create(['name' => 'РОП — 10% от оплаты', 'applies_to' => 'ROP', 'calc_type' => 'PERCENT_OF_PAYMENT', 'value' => 10, 'priority' => 0]);
            CommissionRule::create([
                'name' => 'РОП — 15%, если средний чек > 3000 USD или заказов > 15 за месяц', 'applies_to' => 'ROP',
                'calc_type' => 'PERCENT_OF_PAYMENT', 'value' => 15, 'priority' => 10,
                'conditions' => ['any' => [['metric' => 'avg_check_usd', 'op' => '>', 'value' => 3000], ['metric' => 'orders_count', 'op' => '>', 'value' => 15]]],
            ]);
        }

        // Шаблоны проектов (ТЗ §62) — один раз
        if (ProjectTemplate::count() === 0) {
            foreach (self::PROJECT_TEMPLATES as [$serviceCode, $name, $tasks]) {
                $template = ProjectTemplate::create(['name' => $name, 'service_id' => Service::where('code', $serviceCode)->value('id')]);
                foreach ($tasks as $sort => [$title, $role, $start, $duration]) {
                    $template->tasks()->create(['title' => $title, 'role' => $role, 'start_offset_days' => $start, 'duration_days' => $duration, 'sort' => $sort]);
                }
            }
        }

        // Рабочие графики (ТЗ §36) — один раз. Посещаемость ведётся только у менеджеров и РОП.
        if (WorkSchedule::count() === 0) {
            foreach ([['Менеджеры', 'MANAGER', '09:00'], ['РОП', 'ROP', '09:30']] as [$name, $role, $start]) {
                WorkSchedule::create(['name' => $name, 'role_code' => $role, 'start_time' => $start, 'end_time' => '18:00', 'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 10]);
            }
        }

        foreach (self::FINANCE_CATEGORIES as $i => [$code, $kind, $name, $account, $overhead]) {
            FinanceCategory::firstOrCreate(['code' => $code], ['kind' => $kind, 'name' => $name, 'account_hint' => $account, 'is_overhead' => $overhead, 'sort' => $i]);
        }
        foreach (self::WORK_ITEMS as $i => [$code, $name, $unit, $specialty, $rate, $currency]) {
            WorkItem::firstOrCreate(['code' => $code], ['name' => $name, 'unit' => $unit, 'specialty' => $specialty, 'default_rate' => $rate, 'currency' => $currency, 'sort' => $i]);
        }

        // Тарифы — пример из ТЗ, только если тарифов ещё нет
        if (Tariff::count() === 0) {
            $smm = Service::where('code', 'SMM')->value('id');
            $web = Service::where('code', 'WEBSITE')->value('id');
            $econom = Tariff::create(['service_id' => $smm, 'name' => 'Эконом', 'description' => '8 рилсов, 8 обложек, 4 карусели, 15 сторис в месяц', 'price' => 650, 'currency' => 'USD', 'sort' => 0]);
            foreach ([['REEL', 8], ['COVER', 8], ['CAROUSEL', 4], ['STORY', 15]] as $sort => [$code, $qty]) {
                $econom->items()->create(['kind' => 'PIECE', 'work_item_id' => WorkItem::where('code', $code)->value('id'), 'quantity' => $qty, 'currency' => 'UZS', 'sort' => $sort]);
            }
            foreach ([['Эконом', 'Лендинг до 5 блоков', 750, 3000000, 0], ['Стандарт', 'Корпоративный сайт до 10 страниц', 1150, 5000000, 1]] as [$name, $desc, $price, $pay, $sort]) {
                Tariff::create(['service_id' => $web, 'name' => $name, 'description' => $desc, 'price' => $price, 'currency' => 'USD', 'sort' => $sort])
                    ->items()->create(['kind' => 'FIXED', 'specialty' => 'DEVELOPER', 'amount' => $pay, 'currency' => 'UZS', 'label' => 'Веб-разработчик', 'quantity' => 1, 'sort' => 0]);
            }
        }
    }

    /** Узбекское название — только если его ещё нет (правки в интерфейсе сохраняются). */
    private static function uz($model, string $uz): void
    {
        if (! $model->name_uz) {
            $model->update(['name_uz' => $uz]);
        }
    }
}
