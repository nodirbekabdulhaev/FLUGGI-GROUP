<?php

namespace Tests\Feature\Smm;

use App\Models\SmmIdea;
use App\Models\User;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Общее для тестов автопубликации: идея, минимальные MP4/JPEG, загрузка частями, доставка событий.
 * Нужны свойства $smm, $videographer, $pm, $ceo тестового класса.
 */
trait SmmPublishingHelpers
{
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

    /** Доставка событий: outbox сравнивает время с часами MySQL, а тест «перемотал» время вперёд. */
    private function drain(): void
    {
        DB::table('outbox_events')->whereNull('processed_at')->update(['available_at' => DB::raw('UTC_TIMESTAMP(3)')]);
        while (Outbox::processBatch() > 0);
    }
}
