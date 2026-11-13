<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Налоговые режимы: новые статьи налогов TAX_SOCIAL (социальный налог) и TAX_TURNOVER (налог с оборота).
 * Уже загруженные из выписок платежи этих налогов лежали в «Прочих налогах» (TAX_OTHER) — переносим их
 * по тексту назначения (те же слова, что в BankRules). Повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    private const WORDS = [
        'TAX_SOCIAL' => ['ижтимоий', 'ijtimoiy', 'социальн'],
        'TAX_TURNOVER' => ['айланма', 'aylanma', 'оборот'],
    ];

    public function up(): void
    {
        $rows = DB::table('acc_transactions')->where('direction', 'OUT')->where('category', 'TAX_OTHER')->whereNull('category_id')
            ->get(['id', 'description', 'counterparty']);
        foreach ($rows as $r) {
            $text = mb_strtolower(($r->description ?? '').' | '.($r->counterparty ?? ''));
            // Пеня остаётся в прочих налогах
            if (preg_match('/(пеня|penya)(?!\s*:\s*0)/u', $text)) {
                continue;
            }
            foreach (self::WORDS as $cat => $words) {
                if (array_filter($words, fn ($w) => str_contains($text, $w))) {
                    DB::table('acc_transactions')->where('id', $r->id)->update(['category' => $cat]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('acc_transactions')->whereIn('category', ['TAX_SOCIAL', 'TAX_TURNOVER'])->update(['category' => 'TAX_OTHER']);
    }
};
