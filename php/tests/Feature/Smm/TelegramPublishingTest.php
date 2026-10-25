<?php

namespace Tests\Feature\Smm;

use App\Auth\Access;
use App\Models\AuditLog;
use App\Models\Direction;
use App\Models\Notification;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Models\SmmTelegramChannel;
use App\Models\User;
use App\Services\Smm\SmmIdeaService;
use App\Services\Smm\SmmMedia;
use App\Services\Smm\SmmPublisher;
use App\Services\Smm\TelegramCaption;
use App\Support\Format;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Автопубликация SMM в Telegram-каналы: настройка канала (getChat + getChatMember бота), отправка видео/фото,
 * длинная подпись (1024 + продолжения до 4096), лимит Bot API и версия для Telegram, несколько мест публикации
 * (файл удаляется только после всех), ошибка и повтор места, 429 retry_after, права, перенос прежних публикаций.
 */
class TelegramPublishingTest extends TestCase
{
    use SmmPublishingHelpers;

    private User $ceo;

    private User $pm;

    private User $smm;

    private User $videographer;

    private string $root;

    /** Ответы Bot API: метод → ответ или очередь ответов [[status, body], …]. */
    private array $tg = [];

    /** @var list<array{method:string, fields:array, files:array, body:string}> */
    private array $tgCalls = [];

    /** Ответы Meta (как в InstagramPublishingTest). */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/smm-media-tg-'.uniqid();
        config(['fluggi.smm_media.root' => $this->root, 'fluggi.smm_media.chunk_mb' => 1, 'fluggi.smm_media.max_video_mb' => 5,
            'fluggi.telegram.token' => '7000001:TEST-TOKEN', 'fluggi.telegram.username' => 'fluggi_bot',
            'fluggi.meta.page_access_token' => 'PAGE-TOKEN', 'app.url' => 'https://crm.test']);
        URL::forceRootUrl('https://crm.test');
        URL::forceScheme('https');
        $this->ceo = $this->user('CEO');
        $this->pm = $this->user('PROJECT_MANAGER');
        DB::table('user_directions')->insert(['user_id' => $this->pm->id, 'direction_id' => Direction::where('code', 'MEDIA')->value('id')]);
        $this->smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->videographer = $this->user('EXECUTOR', employee: ['specialties' => ['VIDEOGRAPHER']]);
        $this->tg = [
            'getChat' => [200, ['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'channel', 'title' => 'Fluggi News', 'username' => 'fluggi_news']]],
            'getChatMember' => [200, ['ok' => true, 'result' => ['status' => 'administrator', 'user' => ['id' => 7000001, 'is_bot' => true], 'can_post_messages' => true]]],
            'sendVideo' => [200, ['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => -1001234567890, 'type' => 'channel', 'username' => 'fluggi_news']]]],
            'sendPhoto' => [200, ['ok' => true, 'result' => ['message_id' => 5, 'chat' => ['id' => -1001234567890, 'type' => 'channel']]]],
            'sendMessage' => [200, ['ok' => true, 'result' => ['message_id' => 78]]],
        ];
        $this->meta = [
            'GET me' => ['id' => 'PAGE1', 'instagram_business_account' => ['id' => '1789', 'username' => 'fluggi.uz']],
            'GET 1789/content_publishing_limit' => ['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]],
            'POST 1789/media' => ['id' => 'C1', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/C1'],
            'POST rupload' => ['success' => true],
            'GET C1' => ['status_code' => 'FINISHED'],
            'POST 1789/media_publish' => ['id' => 'M1'],
            'GET M1' => ['permalink' => 'https://www.instagram.com/reel/AbC123/', 'id' => 'M1'],
        ];
        Http::fake(function (Request $r) {
            $url = parse_url($r->url());
            if ($url['host'] === 'api.telegram.org') {
                $this->assertStringStartsWith('/bot7000001:TEST-TOKEN/', $url['path']);
                $method = basename($url['path']);
                $fields = [];
                $files = [];
                if ($r->isMultipart()) {
                    $body = $r->body();
                    foreach ($r->data() as $part) {
                        is_string($part['contents']) ? $fields[$part['name']] = $part['contents'] : $files[$part['name']] = $part['filename'] ?? '';
                    }
                } else {
                    $body = $r->body();
                    $fields = $r->data();
                }
                $this->tgCalls[] = ['method' => $method, 'fields' => $fields, 'files' => $files, 'body' => $body];
                $res = $this->tg[$method] ?? [400, ['ok' => false, 'error_code' => 400, 'description' => 'Unexpected '.$method]];
                if (isset($res[0]) && is_array($res[0])) {
                    $res = count($this->tg[$method]) > 1 ? array_shift($this->tg[$method]) : $this->tg[$method][0];
                }

                return Http::response($res[1], $res[0]);
            }
            $key = $r->method().' '.($url['host'] === 'rupload.facebook.com' ? 'rupload' : preg_replace('#^/v21\.0/#', '', $url['path']));
            $res = $this->meta[$key] ?? ['error' => ['message' => 'Unexpected '.$key, 'code' => 100]];

            return Http::response($res, isset($res['error']) ? 400 : 200);
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        parent::tearDown();
    }

    private function channel(array $extra = []): SmmTelegramChannel
    {
        return SmmTelegramChannel::create($extra + ['chat_id' => '-1001234567890', 'title' => 'Fluggi News', 'username' => 'fluggi_news', 'can_post' => true, 'checked_at' => now()]);
    }

    private function schedule(SmmIdea $idea, array $targets, array $extra = []): TestResponse
    {
        return $this->actingAsUser($this->smm)->put("/smm/{$idea->id}/publication", $extra + [
            'caption' => 'Новый кейс Fluggi', 'share_to_feed' => '1', 'targets_sent' => '1', 'targets' => $targets,
            'scheduled_at' => Format::toLocalInput(now()->addMinutes(5)), 'intent' => 'schedule',
        ]);
    }

    private function target(SmmIdea $idea, string $kind): SmmPublicationTarget
    {
        return SmmPublicationTarget::whereHas('publication', fn ($q) => $q->where('idea_id', $idea->id))->where('kind', $kind)->firstOrFail();
    }

    private function cron(): void
    {
        app()->forgetInstance(Access::class);
        SmmPublisher::tick();
    }

    private function calls(string $method): array
    {
        return array_values(array_filter($this->tgCalls, fn ($c) => $c['method'] === $method));
    }

    private function mediaFile(SmmIdea $idea): ?string
    {
        return SmmMedia::path(SmmPublication::where('idea_id', $idea->id)->value('media_path'));
    }

    public function test_channel_check_admin_ok_not_admin_not_found_and_permissions(): void
    {
        // Только settings.manage
        $this->actingAsUser($this->smm)->post('/settings/integrations/telegram-channels', ['chat' => '@fluggi_news'])->assertForbidden();
        $this->actingAsUser($this->pm)->get('/settings/integrations')->assertForbidden();

        // Инструкция с именем бота
        $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()
            ->assertSee(t('smm.tg.instruction', ['bot' => '@fluggi_bot']))->assertSee(t('smm.tg.noChannels'));

        // Неверный ввод — без запроса в Telegram
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => 'not a channel!'])->assertSessionHasErrors('chat');
        $this->assertSame([], $this->tgCalls);

        // Не найден
        $this->tg['getChat'] = [400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '@unknown_channel'])
            ->assertSessionHasErrors(['chat' => t('smm.tg.errors.notFound', ['bot' => '@fluggi_bot'])]);
        $this->assertSame('@unknown_channel', $this->calls('getChat')[0]['fields']['chat_id']);

        // Бот не администратор / администратор без права публиковать
        $this->tg['getChat'] = [200, ['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'channel', 'title' => 'Fluggi News', 'username' => 'fluggi_news']]];
        $this->tg['getChatMember'] = [200, ['ok' => true, 'result' => ['status' => 'left', 'user' => ['id' => 7000001]]]];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => 'https://t.me/fluggi_news'])
            ->assertSessionHasErrors(['chat' => t('smm.tg.errors.notAdmin', ['bot' => '@fluggi_bot'])]);
        $member = $this->calls('getChatMember')[0]['fields'];
        $this->assertSame(['-1001234567890', 7000001], [(string) $member['chat_id'], $member['user_id']]);
        $this->tg['getChatMember'] = [200, ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => false]]];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '@fluggi_news'])
            ->assertSessionHasErrors(['chat' => t('smm.tg.errors.cannotPost', ['bot' => '@fluggi_bot'])]);
        // Группа — не канал
        $this->tg['getChat'] = [200, ['ok' => true, 'result' => ['id' => -100555, 'type' => 'supergroup', 'title' => 'Chat']]];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '-100555'])->assertSessionHasErrors(['chat' => t('smm.tg.errors.notChannel')]);
        $this->assertSame(0, SmmTelegramChannel::withTrashed()->count());

        // Администратор с правом публиковать — канал сохранён (id, название, @username)
        $this->tg['getChat'] = [200, ['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'channel', 'title' => 'Fluggi News', 'username' => 'fluggi_news']]];
        $this->tg['getChatMember'] = [200, ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]]];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '@fluggi_news'])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $ch = SmmTelegramChannel::firstOrFail();
        $this->assertSame(['-1001234567890', 'Fluggi News', 'fluggi_news', true], [$ch->chat_id, $ch->title, $ch->username, $ch->can_post]);
        $this->assertTrue(AuditLog::where('action', 'integrations.telegram_channel_added')->where('entity_id', $ch->id)->exists());
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '-1001234567890'])->assertSessionHasErrors(['chat' => t('smm.tg.errors.duplicate')]);
        $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()->assertSee('Fluggi News')->assertSee('@fluggi_news')->assertSee(t('smm.tg.canPost'));

        // «Проверить»: бота сняли с администраторов — право снято, текст ошибки сохранён
        $this->tg['getChatMember'] = [200, ['ok' => true, 'result' => ['status' => 'member']]];
        $this->actingAsUser($this->ceo)->postJson("/settings/integrations/telegram-channels/{$ch->id}/check")->assertOk()->assertJson(['ok' => false]);
        $ch->refresh();
        $this->assertFalse($ch->can_post);
        $this->assertSame(t('smm.tg.errors.notAdmin', ['bot' => '@fluggi_bot']), $ch->error);
        $this->assertTrue(AuditLog::where('action', 'integrations.telegram_channel_checked')->exists());
        $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()->assertSee(t('smm.tg.cannotPost'));
        $this->actingAsUser($this->smm)->post("/settings/integrations/telegram-channels/{$ch->id}/check")->assertForbidden();

        // Удаление — мягкое; повторное добавление восстанавливает канал
        $this->actingAsUser($this->smm)->delete("/settings/integrations/telegram-channels/{$ch->id}")->assertForbidden();
        $this->actingAsUser($this->ceo)->delete("/settings/integrations/telegram-channels/{$ch->id}")->assertRedirect();
        $this->assertSoftDeleted($ch);
        $this->tg['getChatMember'] = [200, ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]]];
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '@fluggi_news'])->assertSessionHasNoErrors();
        $this->assertSame([1, $ch->id], [SmmTelegramChannel::count(), SmmTelegramChannel::value('id')]);

        // Бот не настроен
        config(['fluggi.telegram.token' => null]);
        $this->actingAsUser($this->ceo)->post('/settings/integrations/telegram-channels', ['chat' => '@other_channel'])->assertSessionHasErrors(['chat' => t('smm.tg.errors.botNotConfigured')]);
    }

    public function test_video_to_public_channel_with_long_caption_split(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $video = $this->mp4(600_000);
        $this->upload($this->smm, $idea, 'IMG_0001.mp4', $video)->assertOk();
        $this->upload($this->smm, $idea, 'cover.jpg', $this->jpeg(), 'cover')->assertOk();
        $file = $this->mediaFile($idea);
        // Подпись ~1700 символов: абзацы по 100 символов и эмодзи (в Telegram — 2 единицы)
        $lines = array_map(fn ($i) => 'Абзац '.$i.' 🚀 '.str_repeat('слово ', 15), range(1, 17));
        $caption = implode("\n", array_map('trim', $lines));
        $this->schedule($idea, ['tg:'.$ch->id], ['caption' => $caption])->assertSessionHasNoErrors();
        $t = $this->target($idea, 'TELEGRAM');
        $this->assertSame(['SCHEDULED', 'tg:'.$ch->id], [$t->status, $t->target_key]);
        $this->assertSame(0, SmmPublicationTarget::where('kind', 'INSTAGRAM')->count());

        $this->cron();
        $this->assertSame([], $this->tgCalls);
        $this->travel(6)->minutes();
        $this->cron();

        $send = $this->calls('sendVideo');
        $this->assertCount(1, $send);
        $f = $send[0]['fields'];
        $this->assertSame(['-1001234567890', 'true', '1080', '1920', '42', 'attach://cover', 'attach://thumbnail'],
            [$f['chat_id'], $f['supports_streaming'], $f['width'], $f['height'], $f['duration'], $f['cover'], $f['thumbnail']]);
        $this->assertArrayNotHasKey('parse_mode', $f);
        $this->assertSame(['video' => 'IMG_0001.mp4', 'cover' => 'cover.jpg', 'thumbnail' => 'thumb.jpg'], $send[0]['files']);
        // Исходный файл целиком в теле запроса
        $this->assertStringContainsString($video, $send[0]['body']);
        // Подпись к видео — до 1024 (UTF-16), по границе строки; остальное — следующим сообщением
        $this->assertLessThanOrEqual(1024, TelegramCaption::units($f['caption']));
        $this->assertStringEndsWith('слово', $f['caption']);
        $more = $this->calls('sendMessage');
        $this->assertCount(1, $more);
        $this->assertSame('-1001234567890', (string) $more[0]['fields']['chat_id']);
        $this->assertArrayNotHasKey('parse_mode', $more[0]['fields']);
        $this->assertSame($caption, $f['caption']."\n".$more[0]['fields']['text']);

        // Место опубликовано, ссылка t.me/<username>/<id>; идея «Опубликовано»; файлы удалены (других мест нет)
        $t->refresh();
        $this->assertSame(['PUBLISHED', '77', 'https://t.me/fluggi_news/77', 1], [$t->status, $t->external_id, $t->permalink, $t->sent_parts]);
        $idea->refresh();
        $this->assertSame(['PUBLISHED', 'https://t.me/fluggi_news/77'], [$idea->status, $idea->publish_url]);
        $this->assertSame(1, SmmIdeaService::publishedCount($this->smm->id, Format::local(now())->format('Y-m')));
        $this->assertFileDoesNotExist($file);
        $this->assertSame('PUBLISHED', SmmPublication::where('idea_id', $idea->id)->value('status'));
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'tg_published')->exists());
        $this->assertTrue(AuditLog::where('action', 'smm.tg_published')->exists());
        // Временная миниатюра удалена
        $this->assertSame([], glob($this->root.'/tmp/*') ?: []);
        $this->drain();
        foreach ([$this->smm, $this->videographer, $this->pm] as $u) {
            $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'smm.telegram_published')->count(), $u->full_name);
        }
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee('https://t.me/fluggi_news/77')->assertSee('Fluggi News');
    }

    public function test_photo_to_private_channel_and_caption_split_rules(): void
    {
        $ch = $this->channel(['chat_id' => '-1009876543210', 'username' => null, 'title' => 'Закрытый']);
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'post.jpg', $this->jpeg())->assertOk();
        $this->schedule($idea, ['tg:'.$ch->id], ['caption' => 'Короткая подпись'])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $send = $this->calls('sendPhoto');
        $this->assertSame(['-1009876543210', 'Короткая подпись'], [$send[0]['fields']['chat_id'], $send[0]['fields']['caption']]);
        $this->assertSame(['photo' => 'post.jpg'], $send[0]['files']);
        $this->assertCount(0, $this->calls('sendMessage'));
        $this->assertSame(['PUBLISHED', 'https://t.me/c/9876543210/5'], [$this->target($idea, 'TELEGRAM')->status, $this->target($idea, 'TELEGRAM')->permalink]);

        // Разбиение: без переводов строк — по пробелу; без пробелов — ровно по лимиту; продолжения — до 4096
        [$cap, $rest] = TelegramCaption::split(str_repeat('ab ', 500));
        $this->assertLessThanOrEqual(1024, mb_strlen($cap));
        $this->assertStringEndsWith('ab', $cap);
        [$cap, $rest] = TelegramCaption::split(str_repeat('x', 6000));
        $this->assertSame([1024, [4096, 880]], [mb_strlen($cap), array_map('mb_strlen', $rest)]);
        // Эмодзи (2 единицы UTF-16) не разрываются
        [$cap] = TelegramCaption::split(str_repeat('😀', 600));
        $this->assertSame([512, 1024], [mb_strlen($cap), TelegramCaption::units($cap)]);
    }

    public function test_size_limit_validation_and_telegram_version_file(): void
    {
        config(['fluggi.telegram.upload_video_mb' => 1]);
        $ch = $this->channel();
        $idea = $this->idea();
        $original = $this->mp4(2_200_000);
        $this->upload($this->smm, $idea, 'IMG_4K.mov', $original)->assertOk();
        // Видео больше лимита бота — понятная ошибка при планировании, предупреждение в карточке
        $msg = t('smm.tg.errors.videoTooLarge', ['mb' => 1]);
        $this->schedule($idea, ['instagram', 'tg:'.$ch->id])->assertSessionHasErrors(['targets' => $msg]);
        $this->assertSame(0, SmmPublicationTarget::count());
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee($msg)->assertSee(t('smm.tg.versionUpload', ['mb' => 1]));

        // Версия для Telegram: только видео, не больше лимита бота
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'telegram', 'name' => 'small.jpg', 'size' => 1000])
            ->assertStatus(422)->assertJsonPath('message', t('smm.tg.errors.versionType'));
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'telegram', 'name' => 'small.mp4', 'size' => 2 * 1024 * 1024])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $small = $this->mp4(400_000, 1);
        $this->upload($this->smm, $idea, 'small.mp4', $small, 'telegram')->assertOk()->assertJsonPath('message', t('smm.tg.versionUploaded'));
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame(['small.mp4', strlen($small), 'IMG_4K.mov'], [$p->tg_media_name, $p->tg_media_size, $p->media_name]);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'tg_media')->exists());
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertDontSee($msg)->assertSee('small.mp4');

        // Теперь можно: Telegram получает версию, Instagram — исходный файл
        $this->schedule($idea, ['instagram', 'tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $send = $this->calls('sendVideo')[0];
        $this->assertSame('small.mp4', $send['files']['video']);
        $this->assertStringContainsString($small, $send['body']);
        $this->assertStringNotContainsString($original, $send['body']);
        $this->assertSame('1', $send['fields']['duration']);

        // Замена исходного видео удаляет и версию для Telegram (нельзя — уже опубликовано в Telegram)
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'new.mp4', 'size' => 1000])->assertStatus(422);

        $other = $this->idea();
        $this->upload($this->smm, $other, 'a.mp4', $this->mp4(3000))->assertOk();
        $this->upload($this->smm, $other, 'b.mp4', $this->mp4(3000), 'telegram')->assertOk();
        $tgFile = SmmMedia::path(SmmPublication::where('idea_id', $other->id)->value('tg_media_path'));
        $this->upload($this->smm, $other, 'c.mp4', $this->mp4(4000))->assertOk();
        $this->assertFileDoesNotExist($tgFile);
        $this->assertNull(SmmPublication::where('idea_id', $other->id)->value('tg_media_name'));
        // Убрать версию вручную
        $this->upload($this->smm, $other, 'd.mp4', $this->mp4(3000), 'telegram')->assertOk();
        $tgFile = SmmMedia::path(SmmPublication::where('idea_id', $other->id)->value('tg_media_path'));
        $this->actingAsUser($this->smm)->delete("/smm/{$other->id}/publication/telegram-file")->assertRedirect();
        $this->assertFileDoesNotExist($tgFile);
        // Версия без исходного видео — нельзя
        $photo = $this->idea();
        $this->upload($this->smm, $photo, 'p.jpg', $this->jpeg())->assertOk();
        $this->actingAsUser($this->smm)->postJson("/smm/{$photo->id}/publication/uploads", ['purpose' => 'telegram', 'name' => 'v.mp4', 'size' => 1000])
            ->assertStatus(422)->assertJsonPath('message', t('smm.tg.errors.versionNeedsVideo'));
    }

    public function test_both_targets_file_deleted_only_after_both_succeed(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(500_000))->assertOk();
        $this->upload($this->smm, $idea, 'cover.jpg', $this->jpeg(), 'cover')->assertOk();
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        [$file, $cover] = [SmmMedia::path($p->media_path), SmmMedia::path($p->cover_path)];
        $this->schedule($idea, ['instagram', 'tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->assertSame(['SCHEDULED', 'SCHEDULED', 'SCHEDULED'], [$p->fresh()->status, $this->target($idea, 'INSTAGRAM')->status, $this->target($idea, 'TELEGRAM')->status]);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_scheduled')->whereNotNull('changes->targets')->exists());

        // Один запуск: Instagram — контейнер (ждёт обработки Meta), Telegram — опубликовано
        $this->travel(6)->minutes();
        $this->cron();
        $this->assertSame(['PUBLISHING', 'PUBLISHED'], [$this->target($idea, 'INSTAGRAM')->status, $this->target($idea, 'TELEGRAM')->status]);
        // Первое успешное место — идея «Опубликовано», файлы ещё нужны Instagram
        $idea->refresh();
        $publishedAt = $idea->published_at;
        $this->assertSame(['PUBLISHED', 'https://t.me/fluggi_news/77'], [$idea->status, $idea->publish_url]);
        $this->assertSame('PUBLISHING', $p->fresh()->status);
        $this->assertFileExists($file);
        $this->assertFileExists($cover);

        // Instagram опубликован — ссылка идеи на Instagram, дата публикации не меняется, ролик засчитан один раз; файлы удалены
        $this->travel(1)->minutes();
        $this->cron();
        $this->assertSame('PUBLISHED', $this->target($idea, 'INSTAGRAM')->status);
        $idea->refresh();
        $this->assertSame(['PUBLISHED', 'https://www.instagram.com/reel/AbC123/'], [$idea->status, $idea->publish_url]);
        $this->assertEquals($publishedAt, $idea->published_at);
        $this->assertSame(1, SmmIdeaService::publishedCount($this->smm->id, Format::local(now())->format('Y-m')));
        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($cover);
        $p->refresh();
        $this->assertSame(['PUBLISHED', null], [$p->status, $p->media_path]);
        $this->assertNotNull($p->file_deleted_at);
        $this->assertSame(1, SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'status')->where('changes->status->new', 'SCHEDULED')->count());
        $this->drain();
        $this->assertSame(1, Notification::where('user_id', $this->smm->id)->where('type', 'smm.telegram_published')->count());
        $this->assertSame(1, Notification::where('user_id', $this->smm->id)->where('type', 'smm.instagram_published')->count());
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee('https://t.me/fluggi_news/77')->assertSee('https://www.instagram.com/reel/AbC123/');
    }

    public function test_one_target_fails_file_kept_retry_and_uncheck(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(300_000))->assertOk();
        $file = $this->mediaFile($idea);
        $this->tg['sendVideo'] = [400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: wrong file identifier/HTTP URL specified']];
        $this->schedule($idea, ['instagram', 'tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $this->travel(1)->minutes();
        $this->cron();
        $tg = $this->target($idea, 'TELEGRAM');
        $this->assertSame(['FAILED', 'Telegram: Bad Request: wrong file identifier/HTTP URL specified'], [$tg->status, $tg->error]);
        $this->assertSame('PUBLISHED', $this->target($idea, 'INSTAGRAM')->status);
        // Идея опубликована (Instagram), общий статус — ошибка, файл сохранён для повтора
        $this->assertSame('PUBLISHED', $idea->fresh()->status);
        $this->assertSame('FAILED', SmmPublication::where('idea_id', $idea->id)->value('status'));
        $this->assertFileExists($file);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'tg_failed')->exists());
        $this->drain();
        $n = Notification::where('user_id', $this->smm->id)->where('type', 'smm.telegram_failed')->firstOrFail();
        $this->assertStringContainsString('wrong file identifier', $n->body);
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee('wrong file identifier')->assertSee(t('smm.ig.retry'));
        // Исходный файл опубликованной идеи заменить нельзя
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'x.mp4', 'size' => 100])
            ->assertStatus(422)->assertJsonPath('message', t('smm.pub.errors.mediaPublished'));

        // Повтор только этого места (Instagram не публикуется повторно)
        $this->tg['sendVideo'] = [200, ['ok' => true, 'result' => ['message_id' => 90, 'chat' => ['id' => -1001234567890, 'username' => 'fluggi_news']]]];
        $ig = $this->target($idea, 'INSTAGRAM');
        $this->actingAsUser($this->videographer)->post("/smm/{$idea->id}/publication/targets/{$tg->id}/retry")->assertForbidden();
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/targets/{$ig->id}/retry")->assertSessionHas('error', t('smm.ig.errors.retryOnlyFailed'));
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/targets/".Str::uuid().'/retry')->assertNotFound();
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/targets/{$tg->id}/retry")->assertRedirect();
        $this->assertSame('SCHEDULED', $tg->fresh()->status);
        $this->cron();
        $this->assertSame(['PUBLISHED', 'https://t.me/fluggi_news/90'], [$tg->fresh()->status, $tg->fresh()->permalink]);
        $this->assertCount(2, $this->calls('sendVideo'));
        $this->assertFileDoesNotExist($file);
        $this->assertSame('M1', $ig->fresh()->external_id);

        // Другая идея: Telegram не удался → снять галочку → оставшиеся места опубликованы, файл удалён сразу
        $second = $this->idea();
        $this->upload($this->smm, $second, 's.mp4', $this->mp4(3000))->assertOk();
        $file2 = $this->mediaFile($second);
        $this->tg['sendVideo'] = [403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat']];
        $this->schedule($second, ['instagram', 'tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $this->travel(1)->minutes();
        $this->cron();
        $this->assertSame('FAILED', $this->target($second, 'TELEGRAM')->status);
        $this->assertFileExists($file2);
        $this->actingAsUser($this->smm)->put("/smm/{$second->id}/publication", ['intent' => 'draft', 'targets_sent' => '1', 'targets' => ['instagram']])->assertSessionHasNoErrors();
        $this->assertSame(0, SmmPublicationTarget::where('kind', 'TELEGRAM')->whereHas('publication', fn ($q) => $q->where('idea_id', $second->id))->count());
        $this->assertSame('PUBLISHED', SmmPublication::where('idea_id', $second->id)->value('status'));
        $this->assertFileDoesNotExist($file2);
    }

    public function test_failed_target_keeps_file_7_days(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        $file = $this->mediaFile($idea);
        $this->tg['sendVideo'] = [400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: VIDEO_INVALID']];
        $this->schedule($idea, ['tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $this->assertSame('FAILED', $this->target($idea, 'TELEGRAM')->status);
        $this->travel(6)->days();
        $this->cron();
        $this->assertFileExists($file);
        $this->travel(2)->days();
        $this->cron();
        $this->assertFileDoesNotExist($file);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_file_deleted')->exists());
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertSessionHas('error', t('smm.ig.errors.fileGone'));
    }

    public function test_429_retry_after_reschedules_and_does_not_duplicate_sent_media(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        $this->tg['sendVideo'] = [
            [429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 120', 'parameters' => ['retry_after' => 120]]],
            [200, ['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => -1001234567890, 'username' => 'fluggi_news']]]],
        ];
        $this->schedule($idea, ['tg:'.$ch->id], ['caption' => str_repeat("Строка подписи\n", 100)])->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->cron();
        $t = $this->target($idea, 'TELEGRAM');
        // Не ошибка: снова в расписании через retry_after
        $this->assertSame(['SCHEDULED', 1], [$t->status, $t->attempts]);
        $this->assertEqualsWithDelta(now()->addSeconds(121)->getTimestamp(), $t->scheduled_at->getTimestamp(), 2);
        $this->assertStringContainsString('retry after 120', $t->error);
        $this->assertSame('SCHEDULED', SmmPublication::where('idea_id', $idea->id)->value('status'));
        $this->cron();
        $this->assertCount(1, $this->calls('sendVideo'));

        // Видео отправлено, продолжение подписи упёрлось в 429 — при повторе видео не отправляется снова
        $this->tg['sendMessage'] = [
            [429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5', 'parameters' => ['retry_after' => 5]]],
            [200, ['ok' => true, 'result' => ['message_id' => 78]]],
        ];
        $this->travel(3)->minutes();
        $this->cron();
        $t->refresh();
        $this->assertSame(['SCHEDULED', '77', 0], [$t->status, $t->external_id, $t->sent_parts]);
        $this->travel(1)->minutes();
        $this->cron();
        $t->refresh();
        $this->assertSame(['PUBLISHED', 'https://t.me/fluggi_news/77', 1], [$t->status, $t->permalink, $t->sent_parts]);
        $this->assertCount(2, $this->calls('sendVideo'));
        $this->assertCount(2, $this->calls('sendMessage'));
        $this->assertSame('PUBLISHED', $idea->fresh()->status);
    }

    public function test_schedule_validation_and_permissions(): void
    {
        $ch = $this->channel();
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        // Ничего не отмечено
        $this->schedule($idea, [])->assertSessionHasErrors(['targets' => t('smm.pub.errors.noTargets')]);
        // Неизвестное место
        $this->schedule($idea, ['tg:'.Str::uuid()])->assertSessionHasErrors(['targets' => t('smm.pub.errors.unknownTarget')]);
        // Бот не может публиковать в канал
        $ch->update(['can_post' => false]);
        $this->schedule($idea, ['tg:'.$ch->id])->assertSessionHasErrors(['targets' => t('smm.tg.errors.channelNotReady', ['channel' => 'Fluggi News'])]);
        $ch->update(['can_post' => true]);
        // Видеограф видит идею, но места не меняет; чужой SMM-специалист — 404
        $this->actingAsUser($this->videographer)->put("/smm/{$idea->id}/publication", ['intent' => 'schedule', 'targets' => ['tg:'.$ch->id]])->assertForbidden();
        $other = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->actingAsUser($other)->put("/smm/{$idea->id}/publication", ['intent' => 'schedule', 'targets' => ['tg:'.$ch->id]])->assertNotFound();
        $this->actingAsUser($this->user('MANAGER'))->delete("/smm/{$idea->id}/publication/telegram-file")->assertForbidden();
        // Карточка: галочки Instagram и канала
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee('Telegram · Fluggi News')->assertSee('value="tg:'.$ch->id.'"', false);

        // Канал в запланированной публикации удалить нельзя; черновое место снимается при удалении
        $this->schedule($idea, ['tg:'.$ch->id])->assertSessionHasNoErrors();
        $this->actingAsUser($this->ceo)->delete("/settings/integrations/telegram-channels/{$ch->id}")->assertSessionHas('error', t('smm.tg.errors.inUse'));
        $this->actingAsUser($this->smm)->put("/smm/{$idea->id}/publication", ['intent' => 'draft', 'targets_sent' => '1', 'targets' => ['tg:'.$ch->id]])->assertSessionHasNoErrors();
        $this->assertSame('DRAFT', $this->target($idea, 'TELEGRAM')->status);
        $this->actingAsUser($this->ceo)->delete("/settings/integrations/telegram-channels/{$ch->id}")->assertSessionHas('ok');
        $this->assertSame(0, SmmPublicationTarget::count());
        $this->assertTrue(AuditLog::where('action', 'integrations.telegram_channel_removed')->exists());
    }

    public function test_backfill_migration_moves_instagram_state_to_targets(): void
    {
        $published = $this->idea('PUBLISHED');
        $failed = $this->idea();
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = [
            [$published->id, 'PUBLISHED', ['ig_media_id' => 'M9', 'permalink' => 'https://www.instagram.com/reel/X/', 'published_at' => $now, 'attempts' => 1]],
            [$failed->id, 'FAILED', ['error' => 'Meta: boom', 'failed_at' => $now, 'attempts' => 2, 'container_id' => 'C9']],
        ];
        foreach ($rows as [$ideaId, $status, $extra]) {
            DB::table('smm_publications')->insert(['id' => (string) Str::uuid(), 'idea_id' => $ideaId, 'kind' => 'REELS', 'status' => $status,
                'media_name' => 'old.mp4', 'created_at' => $now, 'updated_at' => $now] + $extra);
        }
        $migration = require database_path('migrations/2026_10_26_000000_smm_telegram_publishing.php');
        $migration->up();
        $migration->up();
        $this->assertSame(2, SmmPublicationTarget::count());
        $a = $this->target($published, 'INSTAGRAM');
        $this->assertSame(['PUBLISHED', 'instagram', 'M9', 'https://www.instagram.com/reel/X/', 1], [$a->status, $a->target_key, $a->external_id, $a->permalink, $a->attempts]);
        $this->assertNotNull($a->published_at);
        $b = $this->target($failed, 'INSTAGRAM');
        $this->assertSame(['FAILED', 'Meta: boom', 'C9', 2], [$b->status, $b->error, $b->container_id, $b->attempts]);
        // Карточка показывает перенесённое состояние
        $this->actingAsUser($this->smm)->get("/smm/{$failed->id}")->assertOk()->assertSee('Meta: boom');
    }
}
