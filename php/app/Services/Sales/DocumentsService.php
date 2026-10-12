<?php

namespace App\Services\Sales;

use App\Domain\Words;
use App\Models\Client;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Services\Crm\CrmAccess;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Генерация КП и договора (ТЗ §15, §17): модель документа из блоков, из которой собираются
 * HTML, PDF, Word и TXT с одним закреплённым дизайном (DocumentRenderer). Документы формируются
 * на русском языке, как в прежней версии (сумма прописью — App\Domain\Words). Тексты шаблона
 * договора, вступление и примечание КП — в «Настройки → Документы».
 */
final class DocumentsService
{
    public const KEY = 'documents';

    public const FORMATS = ['html', 'pdf', 'docx', 'txt'];

    /** Поля для подстановки в шаблон договора: {{contract.number}} и т. д. (подписи — lang documents.placeholder.*). */
    public const PLACEHOLDERS = [
        'contract.number', 'contract.date', 'contract.amount', 'contract.amountWords', 'city',
        'company.name', 'company.signer', 'company.director', 'company.position', 'company.basis',
        'client.name', 'client.signer', 'client.director', 'client.position', 'client.basis',
        'services', 'term', 'paymentTerms',
    ];

    /** Реквизиты клиента для договора. */
    public const REQUISITES = ['legalName', 'inn', 'oked', 'director', 'directorPosition', 'signerGenitive', 'basis', 'vatCode', 'address', 'phone', 'bank', 'mfo', 'account'];

    private const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /**
     * Шаблон договора по умолчанию — договор возмездного оказания услуг (ГК РУз, гл. 38).
     * Разметка: «# » — заголовок, «## » — раздел, [[services]] — таблица услуг,
     * [[parties]] — реквизиты и подписи сторон. Остальные строки — абзацы.
     */
    public const DEFAULT_CONTRACT_TEMPLATE = <<<'TXT'
# ДОГОВОР № {{contract.number}}
## возмездного оказания услуг

{{company.name}}, именуемое в дальнейшем «Исполнитель», в лице {{company.signer}}, действующего на основании {{company.basis}}, с одной стороны, и {{client.name}}, именуемое в дальнейшем «Заказчик», в лице {{client.signer}}, действующего на основании {{client.basis}}, с другой стороны, заключили настоящий договор о нижеследующем.

## 1. Предмет договора
1.1. Исполнитель обязуется оказать Заказчику услуги, указанные в Приложении (перечень услуг ниже), а Заказчик обязуется принять и оплатить их.
[[services]]
1.2. Срок оказания услуг: {{term}}.

## 2. Стоимость и порядок расчётов
2.1. Общая стоимость услуг по договору составляет {{contract.amount}} ({{contract.amountWords}}).
2.2. Порядок оплаты: {{paymentTerms}}.
2.3. Оплата производится путём перечисления денежных средств на расчётный счёт Исполнителя.

## 3. Права и обязанности сторон
3.1. Исполнитель обязуется оказать услуги качественно и в срок, согласовывать с Заказчиком результаты работ.
3.2. Заказчик обязуется своевременно предоставлять материалы и информацию, необходимые для оказания услуг, и оплачивать услуги в порядке, установленном договором.
3.3. Заказчик вправе в любое время проверять ход оказания услуг, не вмешиваясь в деятельность Исполнителя.

## 4. Сдача и приёмка услуг
4.1. По итогам оказания услуг (или ежемесячно) стороны подписывают акт выполненных работ (оказанных услуг) и счёт-фактуру.
4.2. Заказчик в течение 5 (пяти) рабочих дней подписывает акт или направляет мотивированный отказ. При отсутствии отказа услуги считаются принятыми.

## 5. Ответственность сторон
5.1. За неисполнение или ненадлежащее исполнение обязательств стороны несут ответственность в соответствии с законодательством Республики Узбекистан.
5.2. За просрочку оплаты Заказчик уплачивает пеню в размере 0,4% от суммы просроченного платежа за каждый день просрочки, но не более 50% суммы просроченного платежа.

## 6. Конфиденциальность
6.1. Стороны обязуются не разглашать сведения, ставшие им известными в связи с исполнением договора.

## 7. Форс-мажор
7.1. Стороны освобождаются от ответственности за неисполнение обязательств, вызванное обстоятельствами непреодолимой силы.

## 8. Разрешение споров
8.1. Споры решаются путём переговоров, а при недостижении согласия — в суде по месту нахождения Исполнителя в соответствии с законодательством Республики Узбекистан.

## 9. Срок действия договора
9.1. Договор вступает в силу с момента подписания и действует до полного исполнения сторонами обязательств.
9.2. Договор составлен в двух экземплярах, имеющих одинаковую юридическую силу, по одному для каждой из сторон.

## 10. Реквизиты и подписи сторон
[[parties]]

TXT;

    public static function defaults(): array
    {
        return [
            'city' => 'г. Ташкент',
            'contractTemplate' => self::DEFAULT_CONTRACT_TEMPLATE,
            'proposalIntro' => 'Благодарим за интерес к нашим услугам. Предлагаем следующие условия сотрудничества.',
            'proposalNote' => '',
        ];
    }

    // ─────────────── Настройки документов ───────────────

    public static function settings(): array
    {
        $saved = Settings::get(self::KEY);
        $out = self::defaults();
        foreach ($out as $k => $v) {
            if (isset($saved[$k]) && is_string($saved[$k])) {
                $out[$k] = $saved[$k];
            }
        }

        return $out;
    }

    public static function saveSettings(array $value): array
    {
        DB::transaction(function () use ($value) {
            Settings::save(self::KEY, $value);
            Audit::log('settings.documents', 'setting', null, ['city' => ['old' => null, 'new' => $value['city']]]);
        });

        return self::settings();
    }

    // ─────────────── Реквизиты клиента ───────────────

    public static function saveClientRequisites(string $clientId, array $input): array
    {
        $before = CrmAccess::client($clientId, 'client.update');
        $value = [];
        foreach (self::REQUISITES as $k) {
            $value[$k] = trim((string) ($input[$k] ?? ''));
        }
        DB::transaction(function () use ($before, $value) {
            $old = $before->requisites;
            $before->update(['requisites' => $value]);
            Audit::log('client.requisites', 'client', $before->id, ['requisites' => ['old' => $old, 'new' => $value]]);
        });

        return $value;
    }

    public static function requisitesOf(Client $c): array
    {
        $r = is_array($c->requisites) ? $c->requisites : [];
        $out = [];
        foreach (self::REQUISITES as $k) {
            $out[$k] = is_string($r[$k] ?? null) ? $r[$k] : '';
        }

        return $out;
    }

    // ─────────────── Форматирование ───────────────

    /** «Иванов Иван Иванович» → «Иванов И.И.» для подписи. */
    public static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return '';
        }
        $last = array_shift($parts);
        $initials = implode('', array_map(fn ($p) => mb_substr($p, 0, 1).'.', $parts));

        return trim($last.' '.$initials);
    }

    private static function join(array $parts, string $sep = ' · '): string
    {
        return implode($sep, array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
    }

    /** «2026-10-07» → «07» октября 2026 г. */
    public static function longDate(string $iso): string
    {
        [$y, $m, $d] = explode('-', substr($iso, 0, 10));

        return "«{$d}» ".self::MONTHS[(int) $m - 1]." {$y} г.";
    }

    /** «2026-10-07» → 07.10.2026 */
    public static function shortDate(string $iso): string
    {
        [$y, $m, $d] = explode('-', substr($iso, 0, 10));

        return "$d.$m.$y";
    }

    /** 7500000 → «7 500 000», дробная часть только если есть. */
    public static function amount(string|int|float $v): string
    {
        $n = (float) $v;
        [$i, $f] = explode('.', number_format(abs($n), 2, '.', ''));
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $i);

        return ($n < 0 ? '−' : '').$grouped.($f === '00' ? '' : ",$f");
    }

    public static function moneyText(string|int|float $v, string $currency): string
    {
        return self::amount($v).' '.($currency === 'UZS' ? 'сум' : $currency);
    }

    /** Подстановка {{key}}; неизвестный или пустой ключ — линия для заполнения от руки. */
    public static function fill(string $text, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function ($m) use ($values) {
            $v = trim((string) ($values[$m[1]] ?? ''));

            return $v !== '' ? $v : '________';
        }, $text);
    }

    /** Шаблон договора → блоки. «# » заголовок, «## » раздел, [[services]] и [[parties]] — вставки. */
    public static function templateBlocks(string $template, array $values, array $inserts, array $meta): array
    {
        $out = [];
        $titleDone = false;
        $lines = explode("\n", str_replace("\r\n", "\n", $template));
        for ($idx = 0; $idx < count($lines); $idx++) {
            $raw = trim($lines[$idx]);
            if ($raw === '') {
                continue;
            }
            if ($raw === '[[services]]') {
                if ($inserts['services']) {
                    $out[] = $inserts['services'];
                }

                continue;
            }
            if ($raw === '[[parties]]') {
                $out[] = $inserts['parties'];

                continue;
            }
            $text = self::fill(preg_replace('/^#{1,2}\s+/u', '', $raw), $values);
            if (str_starts_with($raw, '# ') && ! $titleDone) {
                // Заголовок и подзаголовок («## » сразу после «# »), затем город и дата
                $next = trim($lines[$idx + 1] ?? '');
                $sub = str_starts_with($next, '## ') ? self::fill(substr($next, 3), $values) : null;
                if ($sub !== null) {
                    $idx++;
                }
                $out[] = ['t' => 'title', 'text' => $text, 'sub' => $sub, 'center' => true];
                $out[] = $meta;
                $titleDone = true;
            } elseif (str_starts_with($raw, '#')) {
                $out[] = ['t' => 'h', 'text' => $text];
            } else {
                $out[] = ['t' => 'p', 'text' => $text];
            }
        }
        if (! $titleDone) {
            array_unshift($out, $meta);
        }
        if (! collect($out)->contains(fn ($b) => $b['t'] === 'parties')) {
            $out[] = $inserts['parties'];
        }

        return $out;
    }

    // ─────────────── Общие части ───────────────

    private static function brand(array $co): array
    {
        return [
            'name' => $co['name'] ?: ($co['legalName'] ?: 'Fluggi'),
            'contacts' => self::join([$co['phone'], $co['email'], $co['website'], $co['address']]),
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private static function reqLines(array $r): array
    {
        $rows = [
            ['Адрес', $r['address'] ?? ''], ['ИНН', $r['inn'] ?? ''], ['ОКЭД', $r['oked'] ?? ''],
            ['Рег. код НДС', $r['vatCode'] ?? ''], ['Банк', $r['bank'] ?? ''], ['МФО', $r['mfo'] ?? ''],
            ['Р/с', $r['account'] ?? ''], ['Тел.', $r['phone'] ?? ''],
        ];

        return array_values(array_filter($rows, fn ($x) => trim((string) $x[1]) !== ''));
    }

    private static function companyParty(array $co): array
    {
        return [
            'role' => 'Исполнитель',
            'name' => $co['legalName'] ?: ($co['name'] ?: '________'),
            'lines' => self::reqLines($co),
            'position' => $co['directorPosition'] ?: 'Директор',
            'signer' => self::shortName($co['director']) ?: '________',
        ];
    }

    private static function itemsTable(array $items, string $currency, array $totals): array
    {
        $cur = $currency === 'UZS' ? 'сум' : $currency;
        $rows = [];
        foreach ($items as $idx => $i) {
            $rows[] = [
                (string) ($idx + 1),
                implode("\n", array_filter([
                    $i['description'],
                    self::join([$i['tariffName'] ? "Тариф «{$i['tariffName']}»" : null, $i['tariffDescription']], ': '),
                    (float) $i['discountPct'] ? 'скидка '.Numbers::rate($i['discountPct']).'%' : '',
                ])),
                str_replace('.', ',', Numbers::rate($i['quantity'])),
                self::amount($i['unitPrice']),
                self::amount($i['total']),
            ];
        }

        return [
            't' => 'table',
            'head' => ['№', 'Услуга', 'Кол-во', "Цена, $cur", "Сумма, $cur"],
            'align' => ['left', 'left', 'right', 'right', 'right'],
            'widths' => [0.06, 0.5, 0.1, 0.17, 0.17],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private static function itemLines(string $proposalId): array
    {
        return ProposalItem::with(['tariff:id,name,description'])->where('proposal_id', $proposalId)->orderBy('sort')->get()
            ->map(fn (ProposalItem $i) => [
                'description' => $i->description,
                'tariffName' => $i->tariff?->name,
                'tariffDescription' => $i->tariff?->description,
                'quantity' => (string) $i->quantity,
                'unitPrice' => (string) $i->unit_price,
                'discountPct' => (string) $i->discount_pct,
                'total' => (string) $i->total,
            ])->all();
    }

    // ─────────────── Коммерческое предложение ───────────────

    public static function proposalModel(string $id): array
    {
        $p = ProposalsService::find($id);
        $p->load(['manager:id,full_name', 'client']);
        $co = Settings::company();
        $ds = self::settings();
        $req = self::requisitesOf($p->client);
        $number = Numbers::proposal($p->number);
        $cur = $p->currency;
        $totals = [['label' => 'Сумма', 'value' => self::moneyText((string) $p->subtotal, $cur)]];
        if ((float) $p->discount_amount) {
            $totals[] = ['label' => 'Скидка', 'value' => '− '.self::moneyText((string) $p->discount_amount, $cur)];
        }
        $totals[] = ['label' => 'Итого', 'value' => self::moneyText((string) $p->total, $cur), 'strong' => true];

        $kv = [['Для', $req['legalName'] ?: $p->client->name], ['Проект', $p->title], ['Менеджер', $p->manager->full_name]];
        if ($p->valid_until) {
            $kv[] = ['Действительно до', self::shortDate($p->valid_until->format('Y-m-d'))];
        }
        $blocks = [
            ['t' => 'title', 'text' => 'Коммерческое предложение', 'sub' => "$number от ".self::shortDate($p->updated_at->format('Y-m-d')), 'center' => false],
            ['t' => 'kv', 'rows' => $kv],
        ];
        if (trim($ds['proposalIntro']) !== '') {
            $blocks[] = ['t' => 'p', 'text' => trim($ds['proposalIntro'])];
        }
        if ($p->description) {
            $blocks[] = ['t' => 'p', 'text' => $p->description];
        }
        $blocks[] = self::itemsTable(self::itemLines($p->id), $cur, $totals);
        $blocks[] = ['t' => 'p', 'text' => 'Итого к оплате: '.Words::amountInWords((string) $p->total, $cur).'.'];
        $terms = [];
        if ($p->implementation_term) {
            $terms[] = ['Срок реализации', $p->implementation_term];
        }
        if ($p->payment_terms) {
            $terms[] = ['Условия оплаты', $p->payment_terms];
        }
        if ($terms) {
            $blocks[] = ['t' => 'h', 'text' => 'Условия'];
            $blocks[] = ['t' => 'kv', 'rows' => $terms];
        }
        if (trim($ds['proposalNote']) !== '') {
            $blocks[] = ['t' => 'p', 'text' => trim($ds['proposalNote'])];
        }
        $blocks[] = [
            't' => 'sign',
            'company' => $co['legalName'] ?: ($co['name'] ?: 'Fluggi'),
            'position' => $co['directorPosition'] ?: 'Директор',
            'signer' => self::shortName($co['director']) ?: '________',
        ];

        return ['fileName' => $number, 'title' => "Коммерческое предложение $number", 'brand' => self::brand($co), 'blocks' => $blocks];
    }

    // ─────────────── Договор ───────────────

    public static function contractModel(string $id): array
    {
        $c = ContractsService::find($id);
        $c->load('client');
        $co = Settings::company();
        $ds = self::settings();
        $req = self::requisitesOf($c->client);
        $proposal = $c->proposal_id ? Proposal::find($c->proposal_id) : null;
        $items = $proposal ? self::itemLines($proposal->id) : [];
        $cur = $c->currency;
        $amount = (string) $c->amount;
        $number = Numbers::contract($c->number);
        $date = $c->contract_date->format('Y-m-d');
        $lines = $items && $proposal->currency === $cur ? $items : [[
            'description' => $items ? implode('; ', array_column($items, 'description')) : 'Услуги по договору',
            'tariffName' => null, 'tariffDescription' => null,
            'quantity' => '1', 'unitPrice' => $amount, 'discountPct' => '0', 'total' => $amount,
        ]];
        $itemsSum = array_sum(array_map(fn ($i) => (float) $i['total'], $lines));
        $totals = [['label' => 'Итого по договору', 'value' => self::moneyText($amount, $cur), 'strong' => true]];
        if (abs($itemsSum - (float) $amount) > 0.009) {
            array_unshift($totals, ['label' => 'Сумма по позициям', 'value' => self::moneyText($itemsSum, $cur)]);
        }
        $signer = fn (string $genitive, string $position, string $director) => $genitive ?: self::join([mb_strtolower($position), $director], ' ');
        $values = [
            'contract.number' => $number,
            'contract.date' => self::longDate($date),
            'contract.amount' => self::moneyText($amount, $cur),
            'contract.amountWords' => Words::amountInWords($amount, $cur),
            'city' => $ds['city'],
            'company.name' => $co['legalName'] ?: $co['name'],
            'company.signer' => $signer($co['signerGenitive'], $co['directorPosition'], $co['director']),
            'company.director' => $co['director'],
            'company.position' => $co['directorPosition'],
            'company.basis' => $co['basis'],
            'client.name' => $req['legalName'] ?: $c->client->name,
            'client.signer' => $signer($req['signerGenitive'], $req['directorPosition'], $req['director']),
            'client.director' => $req['director'],
            'client.position' => $req['directorPosition'],
            'client.basis' => $req['basis'],
            'services' => implode('; ', array_column($lines, 'description')),
            'term' => $proposal?->implementation_term ?? '',
            'paymentTerms' => $proposal?->payment_terms ?? '',
        ];
        $parties = ['t' => 'parties', 'parties' => [
            self::companyParty($co),
            [
                'role' => 'Заказчик',
                'name' => $req['legalName'] ?: $c->client->name,
                'lines' => self::reqLines(['phone' => $req['phone'] ?: ($c->client->phone ?? '')] + $req),
                'position' => $req['directorPosition'] ?: 'Директор',
                'signer' => self::shortName($req['director']) ?: '________',
            ],
        ]];
        $blocks = self::templateBlocks(
            $ds['contractTemplate'],
            $values,
            ['services' => self::itemsTable($lines, $cur, $totals), 'parties' => $parties],
            ['t' => 'meta', 'left' => $ds['city'], 'right' => self::longDate($date)],
        );

        return ['fileName' => "Dogovor_$number", 'title' => "Договор $number", 'brand' => self::brand($co), 'blocks' => $blocks];
    }

    /**
     * Каких реквизитов не хватает — показываем перед скачиванием.
     *
     * @return list<string>
     */
    public static function check(string $kind, string $id): array
    {
        $co = Settings::company();
        $missing = [];
        if (! $co['name'] && ! $co['legalName']) {
            $missing[] = t('documents.missing.companyName');
        }
        if ($kind === 'contracts') {
            $c = ContractsService::find($id);
            if (! $co['inn']) {
                $missing[] = t('documents.missing.companyInn');
            }
            if (! $co['bank'] || ! $co['mfo'] || ! $co['account']) {
                $missing[] = t('documents.missing.companyBank');
            }
            if (! $co['director']) {
                $missing[] = t('documents.missing.companyDirector');
            }
            if (! $co['signerGenitive']) {
                $missing[] = t('documents.missing.companySigner');
            }
            $req = self::requisitesOf(Client::withTrashed()->findOrFail($c->client_id));
            if (! $req['legalName']) {
                $missing[] = t('documents.missing.clientLegalName');
            }
            if (! $req['inn']) {
                $missing[] = t('documents.missing.clientInn');
            }
            if (! $req['director']) {
                $missing[] = t('documents.missing.clientDirector');
            }
            if (! $req['signerGenitive']) {
                $missing[] = t('documents.missing.clientSigner');
            }
        } else {
            ProposalsService::find($id);
        }

        return $missing;
    }

    /** Документ с проверкой прав: КП — proposal.read, договор — contract.read (по видимости сделки). */
    public static function model(string $kind, string $id): array
    {
        return $kind === 'contracts' ? self::contractModel($id) : self::proposalModel($id);
    }
}
