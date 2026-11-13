<?php

namespace App\Services\Accounting;

use App\Models\AccCategory;
use Illuminate\Support\Collection;

/**
 * Автоподбор категории для строки выписки — по назначению платежа и контрагенту, без учёта регистра,
 * кириллица и латиница. Порядок важен (первое совпадение):
 *  1. своя статья, у которой есть совпавшее ключевое слово (того же направления, активная);
 *  2. перенос остатка / счёт миграции → перевод (TRANSFER_IN/OUT: не выручка, не база IT Park, не расход);
 *  3. расходы: пеня → прочие налоги; IT Park → TAX_ITPARK; пенсионные взносы/ИНПС → TAX_INPS;
 *     НДФЛ → TAX_NDFL; социальный налог → TAX_SOCIAL; налог с оборота → TAX_TURNOVER; прочие платежи в бюджет (казначейство; слово «налог» —
 *     только у получателя) → TAX_OTHER; тариф/комиссия → BANK_FEE;
 *     зарплата/аванс → SALARY; оплата по договору → SUPPLIER;
 *  4. свой же ИНН компании или пополнение своего счёта → перевод;
 *     погашение кредита (слово «кредит» в назначении) → LOAN_PAYMENT, поступление кредита → LOAN_RECEIPT (не выручка и не расход);
 *  5. приходы по договору/за услуги → CLIENT_PAYMENT; иначе OTHER_INCOME / OTHER.
 * Слова подобраны так, чтобы срабатывать и на выписках, где ҳ/қ/ғ потеряны при выгрузке («соли?»).
 */
final class BankRules
{
    private const MIGRATION = ['сворачивани', 'разворачивани', 'миграци', 'migratsiya', 'перенос остатка', 'qoldiqni ko'];

    /**
     * Перевод со вторичных счетов на основной. Только расход: списание — перевод между своими счетами, а поступление на основной счёт
     * часто и есть выручка (клиенты платят на вторичные счета, которых в системе нет), поэтому приход сам в переводы не попадает.
     */
    private const SWEEP_OUT = ['вторичных счетов', 'вторичных сч', 'на основной счет', 'на основной счёт'];

    private const ITPARK = ['itpark', 'it park', 'it-park', 'ит парк', 'ит-парк'];

    private const INPS = ['пенсия бадал', 'пенсия бадали', 'инпс', 'inps', 'жамғариб', 'жам?ариб', 'jamg\'arib', 'jamgarib', 'накопительн', 'pensiya badal'];

    private const NDFL = ['даромадидан', 'доходы с физических лиц', 'доходов физических лиц', 'ндфл', 'jshds', 'жшдс', 'daromadidan', 'jismoniy shaxslar daromad'];

    private const SOCIAL = ['ижтимоий', 'ijtimoiy', 'социальн'];

    private const TURNOVER = ['айланмадан', 'aylanmadan', 'налог с оборот', 'налога с оборот'];

    /** Платёж в бюджет: казначейство в назначении или у получателя. */
    private const BUDGET = ['$budjet$', 'казначейств', 'молия вазирлиги', 'moliya vazirligi', 'g\'aznachilik', 'газначилик', '$tax_id$'];

    /** Слова «налог» только у получателя: в назначении платежа поставщику они встречаются часто («в т. ч. налог …») и налогом не являются. */
    private const BUDGET_PAYEE = ['солиқ', 'соли?', 'налог', 'soliq'];

    /** Кредит: поступление кредита и погашение (основной долг, проценты) — не выручка и не обычный расход. */
    private const LOAN = ['кредит', 'kredit', 'qarz mablag'];

    private const BANK_FEE = ['начисленные %%', 'тариф', 'комисси', 'komissiya', 'tarif', 'плата за обслуживание', 'xizmat haqi'];

    private const SALARY = ['зарплат', 'заработн', 'аванс', 'ish haqi', 'ish xaqi', 'oylik', 'avans'];

    private const CONTRACT = ['договор', 'шартнома', 'shartnoma', 'за услуги', 'хизматлари учун', 'xizmatlari uchun', 'хизмат учун', 'xizmat uchun', 'оплата за', 'счет-фактур', 'счёт-фактур', 'invoice', 'hisob-faktura'];

    private const OWN_TOPUP = ['пополнение счета', 'пополнение счёта', 'toldirish', 'to\'ldirish', 'тўлдириш', 'толдириш'];

    /** Пеня, но не «втч пеня: 0» в назначении налогового платежа. */
    private const PENALTY_RE = '/(пеня|penya)(?!\s*:\s*0)/u';

    /**
     * @param  array{direction:string,purpose:?string,counterparty:?string,inn:?string}  $row
     * @param  Collection<int,AccCategory>  $articles  активные статьи с ключевыми словами
     * @return array{category:string,category_id:?string,rule:string}
     */
    public static function suggest(array $row, Collection $articles, ?string $companyInn = null): array
    {
        $dir = $row['direction'];
        $text = self::lower(($row['purpose'] ?? '').' | '.($row['counterparty'] ?? ''));
        foreach ($articles as $a) {
            if ($a->direction !== $dir) {
                continue;
            }
            foreach (self::keywords($a->keywords) as $kw) {
                if (str_contains($text, $kw)) {
                    return ['category' => $a->base, 'category_id' => $a->id, 'rule' => 'article'];
                }
            }
        }
        $has = fn (array $words) => (bool) array_filter($words, fn ($w) => str_contains($text, $w));
        $payee = self::lower((string) ($row['counterparty'] ?? ''));
        $pick = fn (string $cat, string $rule) => ['category' => $cat, 'category_id' => null, 'rule' => $rule];

        if ($has(self::MIGRATION)) {
            return $pick($dir === 'IN' ? 'TRANSFER_IN' : 'TRANSFER_OUT', 'migration');
        }
        if ($dir === 'OUT' && $has(self::SWEEP_OUT)) {
            return $pick('TRANSFER_OUT', 'sweep');
        }
        if ($dir === 'OUT') {
            if (preg_match(self::PENALTY_RE, $text)) {
                return $pick('TAX_OTHER', 'penalty');
            }
            foreach ([['TAX_ITPARK', self::ITPARK, 'itpark'], ['TAX_INPS', self::INPS, 'inps'], ['TAX_NDFL', self::NDFL, 'ndfl'],
                ['TAX_SOCIAL', self::SOCIAL, 'social'], ['TAX_TURNOVER', self::TURNOVER, 'turnover'], ['TAX_OTHER', [...self::BUDGET, ...array_filter(self::BUDGET_PAYEE, fn ($w) => str_contains($payee, $w))], 'budget'], ['LOAN_PAYMENT', self::LOAN, 'loan'], ['BANK_FEE', self::BANK_FEE, 'bankFee'], ['SALARY', self::SALARY, 'salary']] as [$cat, $words, $rule]) {
                if ($has($words)) {
                    return $pick($cat, $rule);
                }
            }
        }
        if ($dir === 'IN' && $has(self::LOAN)) {
            return $pick('LOAN_RECEIPT', 'loan');
        }
        $inn = preg_replace('/\D+/', '', (string) ($row['inn'] ?? ''));
        $own = preg_replace('/\D+/', '', (string) $companyInn);
        if (($own !== '' && $inn === $own) || $has(self::OWN_TOPUP)) {
            return $pick($dir === 'IN' ? 'TRANSFER_IN' : 'TRANSFER_OUT', 'own');
        }
        if ($has(self::CONTRACT)) {
            return $pick($dir === 'IN' ? 'CLIENT_PAYMENT' : 'SUPPLIER', 'contract');
        }

        return $pick($dir === 'IN' ? 'OTHER_INCOME' : 'OTHER', 'default');
    }

    /** Ключевые слова статьи: через запятую, точку с запятой или с новой строки. */
    public static function keywords(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\n\r]+/u', (string) $raw) ?: [] as $kw) {
            $kw = self::lower(trim($kw));
            if (mb_strlen($kw) >= 2) {
                $out[] = $kw;
            }
        }

        return array_values(array_unique($out));
    }

    private static function lower(string $s): string
    {
        $s = mb_strtolower($s);

        return (string) preg_replace('/\s+/u', ' ', str_replace(['ё', 'ʻ', 'ʼ', '‘', '’', '`'], ['е', "'", "'", "'", "'", "'"], $s));
    }
}
