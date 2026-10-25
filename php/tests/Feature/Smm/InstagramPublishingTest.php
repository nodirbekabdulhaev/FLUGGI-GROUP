<?php

namespace Tests\Feature\Smm;

use App\Auth\Access;
use App\Models\AuditLog;
use App\Models\Direction;
use App\Models\Notification;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Models\User;
use App\Services\Smm\InstagramPublisher;
use App\Services\Smm\SmmIdeaService;
use App\Services\Smm\SmmMedia;
use App\Support\Format;
use App\Support\Outbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Автопубликация SMM в Instagram: загрузка частями и проверка файла, расписание, cron (контейнер, загрузка
 * исходника, проверка статуса, публикация), идея «Опубликовано» и план роликов, ошибки Meta и повтор, лимит,
 * права, подписанная ссылка на файл, удаление файлов (после публикации, при отмене и замене, у неудачных через 7 дней).
 */
class InstagramPublishingTest extends TestCase
{
    private User $ceo;

    private User $pm;

    private User $smm;

    private User $videographer;

    private string $root;

    /** Ответы Meta: путь → ответ (или очередь ответов). */
    private array $meta = [];

    /** @var list<Request> */
    private array $requests = [];

    /** Тело загрузки на rupload.facebook.com (поток закрывается после отправки). */
    private ?string $uploaded = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/smm-media-test-'.uniqid();
        config(['fluggi.smm_media.root' => $this->root, 'fluggi.smm_media.chunk_mb' => 1, 'fluggi.smm_media.max_video_mb' => 5,
            'fluggi.meta.page_access_token' => 'PAGE-TOKEN', 'app.url' => 'https://crm.test']);
        URL::forceRootUrl('https://crm.test');
        URL::forceScheme('https');
        $this->ceo = $this->user('CEO');
        $this->pm = $this->user('PROJECT_MANAGER');
        DB::table('user_directions')->insert(['user_id' => $this->pm->id, 'direction_id' => Direction::where('code', 'MEDIA')->value('id')]);
        $this->smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->videographer = $this->user('EXECUTOR', employee: ['specialties' => ['VIDEOGRAPHER']]);
        $this->meta = [
            'GET me' => ['id' => 'PAGE1', 'instagram_business_account' => ['id' => '1789', 'username' => 'fluggi.uz']],
            'GET 1789/content_publishing_limit' => ['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]],
            'POST 1789/media' => ['id' => 'C1', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/C1'],
            'POST rupload' => ['success' => true, 'message' => 'Upload successful.'],
            'GET C1' => [['status_code' => 'IN_PROGRESS', 'status' => 'In Progress: Media is still being processed.'], ['status_code' => 'FINISHED', 'status' => 'Finished: Media has been uploaded']],
            'POST 1789/media_publish' => ['id' => 'M1'],
            'GET M1' => ['permalink' => 'https://www.instagram.com/reel/AbC123/', 'id' => 'M1'],
            'GET debug_token' => ['data' => ['is_valid' => true, 'scopes' => ['instagram_basic', 'instagram_content_publish', 'pages_read_engagement', 'pages_show_list']]],
        ];
        Http::fake(function (Request $r) {
            $this->requests[] = $r;
            if (parse_url($r->url(), PHP_URL_HOST) === 'rupload.facebook.com') {
                $this->uploaded = $r->body();
            }
            $url = parse_url($r->url());
            $key = $r->method().' '.($url['host'] === 'rupload.facebook.com' ? 'rupload' : preg_replace('#^/v21\.0/#', '', $url['path']));
            $res = $this->meta[$key] ?? ['error' => ['message' => 'Unexpected '.$key, 'code' => 100]];
            if (array_is_list($res) && isset($res[0]) && is_array($res[0]) && ! isset($res['data'])) {
                $res = count($this->meta[$key]) > 1 ? array_shift($this->meta[$key]) : $this->meta[$key][0];
            }
            $status = isset($res['error']) ? 400 : 200;

            return Http::response($res, $status);
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

    private function idea(string $status = 'EDITING'): SmmIdea
    {
        static $n = 0;
        $n++;

        return SmmIdea::create([
            'title' => 'Ролик '.$n, 'status' => $status, 'author_id' => $this->smm->id, 'assignee_id' => $this->smm->id,
            'videographer_id' => $this->videographer->id, 'sort_order' => $n,
            'pm_approved_by_id' => $this->pm->id, 'ceo_approved_by_id' => $this->ceo->id,
        ])->refresh();
    }

    /** Минимальный MP4: ftyp + moov (mvhd — длительность, tkhd — кадр 1920×1080 с поворотом на 90°) + mdat. */
    private function mp4(int $bytes, int $seconds = 42): string
    {
        $box = fn (string $t, string $d) => pack('N', 8 + strlen($d)).$t.$d;
        $mvhd = $box('mvhd', pack('N5', 0, 0, 0, 1000, $seconds * 1000).str_repeat("\0", 80));
        $tkhd = $box('tkhd', pack('N', 7).pack('N4', 0, 0, 1, 0).pack('N', $seconds * 1000).str_repeat("\0", 16)
            .pack('N9', 0, 0x10000, 0, 0xFFFF0000, 0, 0, 0, 0, 0x40000000).pack('NN', 1920 << 16, 1080 << 16));
        $head = $box('ftyp', 'isom'.pack('N', 512).'isommp42').$box('moov', $mvhd.$box('trak', $tkhd));

        return $head.$box('mdat', str_repeat("\x5A", max(0, $bytes - strlen($head) - 8)));
    }

    private function jpeg(int $w = 1080, int $h = 1350): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 90));
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    /** Загрузка частями, как из браузера; возвращает ответ finish. */
    private function upload(User $as, SmmIdea $idea, string $name, string $bytes, string $purpose = 'media'): TestResponse
    {
        $start = $this->actingAsUser($as)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => $purpose, 'name' => $name, 'size' => strlen($bytes)]);
        if ($start->status() !== 201) {
            return $start;
        }
        $id = $start->json('uploadId');
        foreach (str_split($bytes, $start->json('chunkSize')) as $i => $part) {
            $this->chunk($as, $idea, $id, $i, $part)->assertOk();
        }

        return $this->actingAsUser($as)->postJson("/smm/{$idea->id}/publication/uploads/{$id}/finish");
    }

    private function chunk(User $as, SmmIdea $idea, string $uploadId, int $index, string $bytes): TestResponse
    {
        return $this->actingAsUser($as)->call('POST', "/smm/{$idea->id}/publication/uploads/{$uploadId}/chunk?index={$index}", [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    private function schedule(User $as, SmmIdea $idea, array $extra = []): TestResponse
    {
        return $this->actingAsUser($as)->put("/smm/{$idea->id}/publication", $extra + [
            'caption' => "Новый кейс Fluggi 🚀\n#smm #fluggi", 'share_to_feed' => '1', 'thumb_offset' => '2.5',
            'scheduled_at' => Format::toLocalInput(now()->addMinutes(10)), 'intent' => 'schedule',
        ]);
    }

    private function sent(string $key): array
    {
        return array_values(array_filter($this->requests, function (Request $r) use ($key) {
            $url = parse_url($r->url());

            return $r->method().' '.($url['host'] === 'rupload.facebook.com' ? 'rupload' : preg_replace('#^/v21\.0/#', '', $url['path'])) === $key;
        }));
    }

    /** Запуск cron: без вошедшего сотрудника (как на хостинге). */
    private function cron(bool $cleanupOnly = false): void
    {
        app()->forgetInstance(Access::class);
        $cleanupOnly ? InstagramPublisher::cleanup() : InstagramPublisher::tick();
    }

    /** Доставка событий: outbox сравнивает время с часами MySQL, а тест «перемотал» время вперёд. */
    private function drain(): void
    {
        DB::table('outbox_events')->whereNull('processed_at')->update(['available_at' => DB::raw('UTC_TIMESTAMP(3)')]);
        while (Outbox::processBatch() > 0);
    }

    public function test_chunked_upload_assembly_and_validation(): void
    {
        $idea = $this->idea();
        $video = $this->mp4(2_500_000);
        // Тип и размер проверяются до загрузки
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'clip.avi', 'size' => 1000])
            ->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.mediaType'));
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'clip.mp4', 'size' => 6 * 1024 * 1024])
            ->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.tooLarge', ['mb' => 5]));
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'cover', 'name' => 'cover.mp4', 'size' => 1000])->assertStatus(422);

        $start = $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'IMG_0042.mp4', 'size' => strlen($video)])->assertCreated();
        $id = $start->json('uploadId');
        $this->assertSame(1024 * 1024, $start->json('chunkSize'));
        $parts = str_split($video, 1024 * 1024);
        $this->chunk($this->smm, $idea, $id, 0, $parts[0])->assertOk()->assertJson(['received' => 1024 * 1024]);
        // Повтор той же части — без изменений; пропуск части — ошибка
        $this->chunk($this->smm, $idea, $id, 0, $parts[0])->assertOk()->assertJson(['received' => 1024 * 1024]);
        $this->chunk($this->smm, $idea, $id, 2, $parts[2])->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.chunkOrder'));
        // Больше части или чужая загрузка
        $this->chunk($this->smm, $idea, $id, 1, str_repeat('x', 1024 * 1024 + 1))->assertStatus(422);
        $this->chunk($this->ceo, $idea, $id, 1, $parts[1])->assertNotFound();
        // Не всё загружено — собрать нельзя
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads/{$id}/finish")->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.incomplete'));
        $this->assertDirectoryDoesNotExist($this->root.'/chunks/'.$id);

        $r = $this->upload($this->smm, $idea, 'IMG_0042.mp4', $video)->assertOk()->assertJson(['kind' => 'REELS', 'info' => ['seconds' => 42, 'width' => 1080, 'height' => 1920]]);
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame(['DRAFT', 'REELS', 'IMG_0042.mp4', strlen($video), 'video/mp4'], [$p->status, $p->kind, $p->media_name, $p->media_size, $p->media_mime]);
        $this->assertSame($video, file_get_contents(SmmMedia::path($p->media_path)));
        // Части удалены сразу после сборки
        $this->assertSame([], array_values(array_diff(scandir($this->root.'/chunks'), ['.', '..'])));
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_media')->exists());
        $this->assertTrue(AuditLog::where('action', 'smm.ig_media')->where('entity_id', $p->id)->exists());

        // Содержимое не видео, хоть и .mp4; слишком короткий ролик
        $this->upload($this->smm, $idea, 'fake.mp4', str_repeat('not a video', 100))->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.mediaType'));
        $this->upload($this->smm, $idea, 'short.mp4', $this->mp4(5000, 1))->assertStatus(422);
        // Фото: PNG → JPEG, неподходящее соотношение сторон
        $img = imagecreatetruecolor(1080, 1080);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        $other = $this->idea();
        $this->upload($this->smm, $other, 'post.png', $png)->assertOk()->assertJson(['kind' => 'IMAGE', 'name' => 'post.jpg']);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file(SmmMedia::path(SmmPublication::where('idea_id', $other->id)->value('media_path'))));
        $this->upload($this->smm, $other, 'wide.jpg', $this->jpeg(3000, 1000))->assertStatus(422)->assertJsonPath('message', t('smm.ig.errors.imageRatio'));
    }

    public function test_permissions_non_editor_gets_403_and_stranger_404(): void
    {
        $idea = $this->idea();
        // Видеограф видит идею, но не редактирует
        $this->actingAsUser($this->videographer)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'a.mp4', 'size' => 100])->assertForbidden();
        $this->actingAsUser($this->videographer)->put("/smm/{$idea->id}/publication", ['intent' => 'draft'])->assertForbidden();
        $this->actingAsUser($this->videographer)->delete("/smm/{$idea->id}/publication")->assertForbidden();
        $this->actingAsUser($this->videographer)->get("/smm/{$idea->id}")->assertOk()->assertDontSee(t('smm.ig.uploadMedia'));
        // Другой SMM-специалист (OWN) идею не видит
        $other = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->actingAsUser($other)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'a.mp4', 'size' => 100])->assertNotFound();
        // Менеджер без права smm.read
        $this->actingAsUser($this->user('MANAGER'))->put("/smm/{$idea->id}/publication", ['intent' => 'draft'])->assertForbidden();
        // Автор и проект-менеджер — могут
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.ig.uploadMedia'));
        $this->actingAsUser($this->pm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'a.mp4', 'size' => 100])->assertCreated();
    }

    public function test_schedule_requires_approval_media_and_future_time(): void
    {
        $idea = $this->idea('IDEA');
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(4000))->assertOk();
        $this->schedule($this->smm, $idea)->assertSessionHas('error', t('smm.ig.errors.notApproved'));
        $idea->update(['status' => 'APPROVED']);
        $this->schedule($this->smm, $idea, ['scheduled_at' => Format::toLocalInput(now()->subHour())])->assertSessionHasErrors('scheduled_at');
        $long = str_repeat('я', 2201);
        $this->schedule($this->smm, $idea, ['caption' => $long])->assertSessionHasErrors('caption');
        $this->schedule($this->smm, $idea, ['caption' => implode(' ', array_map(fn ($i) => '#tag'.$i, range(1, 31)))])->assertSessionHasErrors('caption');
        $this->assertSame('DRAFT', SmmPublication::where('idea_id', $idea->id)->value('status'));
        $this->schedule($this->smm, $idea)->assertRedirect("/smm/{$idea->id}#instagram");
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame(['SCHEDULED', 2500, true], [$p->status, $p->thumb_offset_ms, $p->share_to_feed]);
        $this->assertSame("Новый кейс Fluggi 🚀\n#smm #fluggi", $p->caption);
        // Идея переходит в «Запланировано» с датой публикации (по Ташкенту)
        $idea->refresh();
        $this->assertSame(['SCHEDULED', Format::local($p->scheduled_at)->format('Y-m-d')], [$idea->status, $idea->planned_publish_date->format('Y-m-d')]);
        // Без файла — нельзя
        $empty = $this->idea();
        $this->schedule($this->smm, $empty)->assertSessionHasErrors('file');
    }

    public function test_reels_publish_flow_marks_idea_published_counts_for_salary_and_deletes_file(): void
    {
        $idea = $this->idea();
        $video = $this->mp4(2_200_000);
        $this->upload($this->smm, $idea, 'IMG_0001.mp4', $video)->assertOk();
        $this->upload($this->smm, $idea, 'cover.jpg', $this->jpeg(), 'cover')->assertOk();
        $this->schedule($this->smm, $idea)->assertSessionHasNoErrors();
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $mediaPath = SmmMedia::path($p->media_path);
        $coverPath = SmmMedia::path($p->cover_path);

        // До времени — ничего
        $this->cron();
        $this->assertSame([], $this->requests);
        $this->travel(11)->minutes();
        $this->cron();
        $p->refresh();
        $this->assertSame(['PUBLISHING', 'C1'], [$p->status, $p->container_id]);
        // Лимит публикаций проверен, контейнер Reels — resumable с подписью, «в ленте», кадром и обложкой по подписанной ссылке
        $this->assertCount(1, $this->sent('GET 1789/content_publishing_limit'));
        $container = $this->sent('POST 1789/media')[0]->data();
        $this->assertSame(['REELS', 'resumable', "Новый кейс Fluggi 🚀\n#smm #fluggi", 'true', '2500', 'PAGE-TOKEN'],
            [$container['media_type'], $container['upload_type'], $container['caption'], $container['share_to_feed'], $container['thumb_offset'], $container['access_token']]);
        $this->assertStringStartsWith('https://crm.test/hooks/ig-media/'.$p->id.'/cover?expires=', $container['cover_url']);
        // Исходный файл целиком — на адрес загрузки
        $upload = $this->sent('POST rupload')[0];
        $this->assertSame('https://rupload.facebook.com/ig-api-upload/v21.0/C1', $upload->url());
        $this->assertSame(['OAuth PAGE-TOKEN', '0', (string) strlen($video)], [$upload->header('Authorization')[0], $upload->header('offset')[0], $upload->header('file_size')[0]]);
        $this->assertSame($video, $this->uploaded);
        // Ссылка на обложку открывается без входа
        $this->flushSession();
        $this->get(str_replace('https://crm.test', '', $container['cover_url']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Статус контейнера — в следующих запусках
        $this->cron();
        $this->assertCount(0, $this->sent('GET C1'));
        $this->travel(1)->minutes();
        $this->cron();
        $this->assertSame('PUBLISHING', $p->fresh()->status);
        $this->travel(2)->minutes();
        $this->cron();
        $p->refresh();
        $this->assertSame(['PUBLISHED', 'M1', 'https://www.instagram.com/reel/AbC123/'], [$p->status, $p->ig_media_id, $p->permalink]);
        $this->assertSame('C1', $this->sent('POST 1789/media_publish')[0]->data()['creation_id']);

        // Файлы удалены сразу, метаданные остались
        $this->assertFileDoesNotExist($mediaPath);
        $this->assertFileDoesNotExist($coverPath);
        $this->assertNull($p->media_path);
        $this->assertNotNull($p->file_deleted_at);
        $this->assertSame(['IMG_0001.mp4', strlen($video)], [$p->media_name, $p->media_size]);

        // Идея — «Опубликовано» со ссылкой; засчитывается в план роликов ответственному и видеографу
        $idea->refresh();
        $this->assertSame(['PUBLISHED', 'https://www.instagram.com/reel/AbC123/'], [$idea->status, $idea->publish_url]);
        $this->assertNotNull($idea->published_at);
        $period = Format::local(now())->format('Y-m');
        $this->assertSame(1, SmmIdeaService::publishedCount($this->smm->id, $period));
        $this->assertSame(1, SmmIdeaService::publishedCount($this->videographer->id, $period));
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_published')->exists());
        $this->assertTrue(AuditLog::where('action', 'smm.ig_published')->where('entity_id', $p->id)->exists());
        $this->drain();
        foreach ([$this->smm, $this->videographer, $this->pm] as $u) {
            $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'smm.instagram_published')->count(), $u->full_name);
        }
        // Ссылка на файл после публикации не работает; карточка показывает «Файл удалён после публикации»
        $this->get(str_replace('https://crm.test', '', $container['cover_url']))->assertNotFound();
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.ig.fileDeletedPublished'))->assertSee('https://www.instagram.com/reel/AbC123/');
        // Опубликованную нельзя менять
        $this->schedule($this->smm, $idea)->assertSessionHas('error', t('smm.ig.errors.locked'));
    }

    public function test_photo_uses_signed_image_url(): void
    {
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'post.jpg', $this->jpeg())->assertOk();
        $this->schedule($this->smm, $idea, ['scheduled_at' => Format::toLocalInput(now()->addMinutes(2))]);
        $this->meta['GET C1'] = [['status_code' => 'FINISHED']];
        $this->travel(3)->minutes();
        $this->cron();
        $data = $this->sent('POST 1789/media')[0]->data();
        $this->assertArrayNotHasKey('media_type', $data);
        $this->assertStringStartsWith('https://crm.test/hooks/ig-media/', $data['image_url']);
        $this->assertCount(0, $this->sent('POST rupload'));
        $path = str_replace('https://crm.test', '', $data['image_url']);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // Подпись изменена или срок истёк — 403
        $this->get(preg_replace('/signature=\w+/', 'signature=deadbeef', $path))->assertForbidden();
        $this->get(preg_replace('/\/media\?/', '/cover?', $path))->assertForbidden();
        $this->travel(7)->hours();
        $this->get($path)->assertForbidden();
        // Подписанная ссылка на несуществующую публикацию — 404
        $this->get(str_replace('https://crm.test', '', URL::temporarySignedRoute('smm.media.public', now()->addHour(), ['id' => (string) Str::uuid(), 'kind' => 'media'])))->assertNotFound();
        $this->travel(1)->minutes();
        $this->cron();
        $this->assertSame('PUBLISHED', SmmPublication::where('idea_id', $idea->id)->value('status'));
    }

    public function test_meta_error_fails_with_message_notifies_author_and_retry_works(): void
    {
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        $this->schedule($this->smm, $idea);
        $this->meta['GET C1'] = [['status_code' => 'ERROR', 'status' => 'Error: The video format is not supported. Please check spec for supported codec']];
        $this->travel(11)->minutes();
        $this->cron();
        $this->travel(1)->minutes();
        $this->cron();
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame('FAILED', $p->status);
        $this->assertStringContainsString('The video format is not supported', $p->error);
        $this->assertNotNull($p->failed_at);
        // Файл сохраняется для повтора, идея не опубликована
        $this->assertTrue(SmmMedia::exists($p->media_path));
        $this->assertSame('SCHEDULED', $idea->fresh()->status);
        $this->drain();
        $n = Notification::where('user_id', $this->smm->id)->where('type', 'smm.instagram_failed')->firstOrFail();
        $this->assertStringContainsString('The video format is not supported', $n->body);
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.ig.retry'))->assertSee('The video format is not supported');

        // Ошибка создания контейнера (текст Meta)
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertRedirect();
        $this->assertSame('SCHEDULED', $p->fresh()->status);
        $this->meta['POST 1789/media'] = ['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'The aspect ratio is not supported.', 'code' => 100]];
        $this->cron();
        $this->assertSame(['FAILED', 'Meta: The aspect ratio is not supported.'], [$p->fresh()->status, $p->fresh()->error]);

        // Повтор после исправления — публикуется
        $this->meta['POST 1789/media'] = ['id' => 'C1', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/C1'];
        $this->meta['GET C1'] = [['status_code' => 'FINISHED']];
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertRedirect();
        $this->cron();
        $this->travel(1)->minutes();
        $this->cron();
        $this->assertSame('PUBLISHED', $p->fresh()->status);
        $this->assertSame('PUBLISHED', $idea->fresh()->status);
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertSessionHas('error', t('smm.ig.errors.retryOnlyFailed'));
    }

    public function test_publishing_rate_limit_and_missing_permission(): void
    {
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        $this->schedule($this->smm, $idea);
        $this->meta['GET 1789/content_publishing_limit'] = ['data' => [['quota_usage' => 50, 'config' => ['quota_total' => 50]]]];
        $this->travel(11)->minutes();
        $this->cron();
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame(['FAILED', t('smm.ig.errors.quota', ['usage' => 50, 'total' => 50])], [$p->status, $p->error]);
        $this->assertCount(0, $this->sent('POST 1789/media'));

        // Нет права instagram_content_publish — понятная подсказка
        $this->meta['GET 1789/content_publishing_limit'] = ['error' => ['message' => '(#10) The user is not an Instagram Business or does not have instagram_content_publish permission', 'code' => 10]];
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('SCHEDULED', $p->fresh()->status);
        $this->cron();
        $this->assertStringContainsString(t('smm.ig.errors.permission', ['error' => '']), $p->fresh()->error.' ');
    }

    public function test_settings_check_access_button_and_disk_usage(): void
    {
        $this->actingAsUser($this->smm)->post('/settings/integrations/instagram/check')->assertForbidden();
        $this->actingAsUser($this->ceo)->postJson('/settings/integrations/instagram/check')->assertOk()
            ->assertJson(['ok' => true, 'username' => 'fluggi.uz', 'igUserId' => '1789', 'quotaUsage' => 3, 'quotaTotal' => 50, 'missingScopes' => []]);
        $this->assertTrue(AuditLog::where('action', 'integrations.instagram_checked')->exists());
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3 * 1024 * 1024))->assertOk();
        $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()
            ->assertSee(t('smm.ig.accessOk'))->assertSee('@fluggi.uz')->assertSee(t('smm.ig.disk', ['size' => '3,0 МБ']));

        // Токену не хватает права — текст для владельца
        $this->meta['GET debug_token'] = ['data' => ['is_valid' => true, 'scopes' => ['instagram_basic', 'pages_read_engagement']]];
        $r = $this->actingAsUser($this->ceo)->postJson('/settings/integrations/instagram/check')->assertOk()->json();
        $this->assertFalse($r['ok']);
        $this->assertSame(['instagram_content_publish'], $r['missingScopes']);
        $this->assertStringContainsString('instagram_content_publish', $r['error']);
        $this->actingAsUser($this->ceo)->post('/settings/integrations/instagram/check')->assertSessionHas('error');
        $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()->assertSee(t('smm.ig.accessError'));
    }

    public function test_files_deleted_on_cancel_and_replace(): void
    {
        $idea = $this->idea();
        $this->upload($this->smm, $idea, 'first.mp4', $this->mp4(3000))->assertOk();
        $first = SmmMedia::path(SmmPublication::where('idea_id', $idea->id)->value('media_path'));
        $this->upload($this->smm, $idea, 'cover.jpg', $this->jpeg(), 'cover')->assertOk();
        $cover = SmmMedia::path(SmmPublication::where('idea_id', $idea->id)->value('cover_path'));
        // Замена видео — прежний файл удалён
        $this->upload($this->smm, $idea, 'second.mp4', $this->mp4(4000))->assertOk();
        $this->assertFileDoesNotExist($first);
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $this->assertSame('second.mp4', $p->media_name);
        // Фото вместо видео — обложка не нужна и удалена
        $second = SmmMedia::path($p->media_path);
        $this->upload($this->smm, $idea, 'post.jpg', $this->jpeg())->assertOk();
        $this->assertFileDoesNotExist($second);
        $this->assertFileDoesNotExist($cover);
        $p->refresh();
        $this->assertSame(['IMAGE', null], [$p->kind, $p->cover_path]);
        $photo = SmmMedia::path($p->media_path);

        // Отмена запланированной — запись и файл удалены
        $this->schedule($this->smm, $idea);
        $this->actingAsUser($this->smm)->delete("/smm/{$idea->id}/publication")->assertRedirect();
        $this->assertFileDoesNotExist($photo);
        $this->assertSame(0, SmmPublication::where('idea_id', $idea->id)->count());
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_cancelled')->exists());
        $this->assertTrue(AuditLog::where('action', 'smm.ig_cancelled')->exists());
        $this->assertSame(0, (int) SmmMedia::diskUsage());

        // Во время публикации — нельзя ни заменить, ни отменить
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        SmmPublication::where('idea_id', $idea->id)->update(['status' => 'PUBLISHING', 'publishing_started_at' => now()]);
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'x.mp4', 'size' => 100])->assertStatus(422);
        $this->actingAsUser($this->smm)->delete("/smm/{$idea->id}/publication")->assertSessionHas('error', t('smm.ig.errors.locked'));
    }

    public function test_cleanup_abandoned_chunks_and_failed_files_after_7_days(): void
    {
        $idea = $this->idea();
        // Брошенная загрузка
        $start = $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/publication/uploads", ['purpose' => 'media', 'name' => 'a.mp4', 'size' => 5000])->assertCreated();
        $this->chunk($this->smm, $idea, $start->json('uploadId'), 0, str_repeat('a', 2000))->assertOk();
        $dir = $this->root.'/chunks/'.$start->json('uploadId');
        $this->cron(cleanupOnly: true);
        $this->assertDirectoryExists($dir);
        touch($dir.'/data.part', time() - 7 * 3600);
        file_put_contents($dir.'/meta.json', json_encode(['createdAt' => time() - 7 * 3600] + json_decode(file_get_contents($dir.'/meta.json'), true)));
        $this->cron(cleanupOnly: true);
        $this->assertDirectoryDoesNotExist($dir);

        // Неудавшаяся публикация: файл хранится 7 дней
        $this->upload($this->smm, $idea, 'r.mp4', $this->mp4(3000))->assertOk();
        $p = SmmPublication::where('idea_id', $idea->id)->firstOrFail();
        $file = SmmMedia::path($p->media_path);
        $p->update(['status' => 'FAILED', 'error' => 'Meta: error', 'failed_at' => now()]);
        $this->travel(6)->days();
        $this->cron();
        $this->assertFileExists($file);
        $this->travel(2)->days();
        $this->cron();
        $this->assertFileDoesNotExist($file);
        $p->refresh();
        $this->assertSame(['FAILED', null, 'r.mp4'], [$p->status, $p->media_path, $p->media_name]);
        $this->assertNotNull($p->file_deleted_at);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'ig_file_deleted')->exists());
        // Повтор без файла невозможен, в карточке — «Файл удалён»
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/publication/retry")->assertSessionHas('error', t('smm.ig.errors.fileGone'));
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.ig.fileDeleted'))->assertDontSee(t('smm.ig.retry'));
    }
}
