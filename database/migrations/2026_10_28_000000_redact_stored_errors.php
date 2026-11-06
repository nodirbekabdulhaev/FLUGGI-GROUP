<?php

use App\Support\Secrets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ошибки сети сохранялись с полным адресом запроса — в нём был токен бота или access_token Meta.
 * Скрываем секреты в уже сохранённых текстах ошибок. Повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'smm_publication_targets' => 'error',
            'smm_publications' => 'error',
            'notification_deliveries' => 'last_error',
            'smm_telegram_channels' => 'error',
        ];
        foreach ($columns as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            DB::table($table)->whereNotNull($column)
                ->where(fn ($q) => $q->where($column, 'like', '%/bot%')->orWhere($column, 'like', '%access_token=%'))
                ->orderBy('id')->select(['id', $column])
                ->chunk(500, function ($rows) use ($table, $column) {
                    foreach ($rows as $r) {
                        $clean = Secrets::redact($r->{$column});
                        if ($clean !== $r->{$column}) {
                            DB::table($table)->where('id', $r->id)->update([$column => $clean]);
                        }
                    }
                });
        }
        $health = DB::table('settings')->whereIn('key', ['meta.health', 'telegram.health'])->get(['key', 'value']);
        foreach ($health as $h) {
            $clean = Secrets::redact($h->value);
            if ($clean !== $h->value) {
                DB::table('settings')->where('key', $h->key)->update(['value' => $clean]);
            }
        }
    }

    public function down(): void {}
};
