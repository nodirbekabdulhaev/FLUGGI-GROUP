<?php

namespace App\Console\Commands;

use App\Services\Push\WebPushCrypto;
use Illuminate\Console\Command;

/**
 * Ключи VAPID для Web Push: php artisan fluggi:webpush-keys — создать и записать VAPID_PUBLIC_KEY /
 * VAPID_PRIVATE_KEY в .env, если их там ещё нет (повторный запуск ничего не меняет: смена ключей
 * отключила бы все подписки браузеров). --show — только показать новую пару, не записывая.
 */
class WebPushKeys extends Command
{
    protected $signature = 'fluggi:webpush-keys {--show : Только показать новую пару ключей} {--env= : Путь к .env (по умолчанию — .env проекта)}';

    protected $description = 'Создать ключи VAPID для Web Push и записать их в .env (если их нет)';

    public function handle(): int
    {
        if ($this->option('show')) {
            $keys = WebPushCrypto::generateKeys();
            $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['private']);

            return self::SUCCESS;
        }
        $path = (string) ($this->option('env') ?: base_path('.env'));
        if (! is_file($path) || ! is_writable($path)) {
            $this->error("Файл $path не найден или недоступен для записи. Ключи: php artisan fluggi:webpush-keys --show");

            return self::FAILURE;
        }
        $env = (string) file_get_contents($path);
        $public = self::value($env, 'VAPID_PUBLIC_KEY');
        $private = self::value($env, 'VAPID_PRIVATE_KEY');
        if ($public !== '' && $private !== '') {
            $this->info('Ключи VAPID уже заданы в .env — оставлены без изменений.');
            $this->line('VAPID_PUBLIC_KEY='.$public);

            return self::SUCCESS;
        }
        if ($public !== '' || $private !== '') {
            $this->error('В .env задан только один из ключей VAPID — удалите его или допишите второй вручную.');

            return self::FAILURE;
        }
        $keys = WebPushCrypto::generateKeys();
        foreach (['VAPID_PUBLIC_KEY' => $keys['public'], 'VAPID_PRIVATE_KEY' => $keys['private']] as $name => $value) {
            $env = preg_match('/^'.$name.'=.*$/m', $env)
                ? preg_replace('/^'.$name.'=.*$/m', $name.'='.$value, $env)
                : rtrim($env, "\n")."\n".$name.'='.$value."\n";
        }
        file_put_contents($path, $env);
        $this->info('Ключи VAPID записаны в .env. Выполните php artisan config:cache (если конфигурация кэшируется).');
        $this->line('VAPID_PUBLIC_KEY='.$keys['public']);

        return self::SUCCESS;
    }

    private static function value(string $env, string $name): string
    {
        return preg_match('/^'.$name.'=(.*)$/m', $env, $m) ? trim($m[1], " \t\"'\r") : '';
    }
}
