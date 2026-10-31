<?php

namespace Tests\Feature\Smm;

use App\Services\Smm\InstagramApi;
use App\Services\Smm\InstagramError;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use ReflectionMethod;
use Tests\TestCase;

/** Текст ошибки Meta понятен: сервер загрузки (debug_info), вложенный JSON, пустой ответ — без токенов. */
class InstagramErrorTextTest extends TestCase
{
    private function message(array|string $body, int $status = 400): string
    {
        $res = new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], is_array($body) ? json_encode($body) : $body));
        $m = new ReflectionMethod(InstagramApi::class, 'json');
        try {
            $m->invoke(null, $res);
        } catch (InstagramError $e) {
            return $e->getMessage();
        }
        $this->fail('no error');
    }

    public function test_error_texts(): void
    {
        config(['fluggi.meta.page_access_token' => 'EAAPAGESECRETTOKEN123']);
        $this->assertSame('Meta: ProcessingFailedError: Video too short',
            $this->message(['debug_info' => ['retriable' => false, 'type' => 'ProcessingFailedError', 'message' => 'Video too short']]));
        $this->assertSame('Meta: ProcessingFailedError: Invalid OAuth access token. (code 190)',
            $this->message(['debug_info' => ['type' => 'ProcessingFailedError', 'message' => json_encode(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]])]]));
        $this->assertSame('Meta: Media upload has failed: The video file you selected is in a format that we don\'t support. (code 9004/2207026)',
            $this->message(['error' => ['message' => 'Unsupported format', 'error_user_title' => 'Media upload has failed', 'error_user_msg' => 'The video file you selected is in a format that we don\'t support.', 'code' => 9004, 'error_subcode' => 2207026]]));
        $this->assertSame('Meta: HTTP 400 — bad token EAA*** here', str_replace('***', 'EAA***', $this->message('bad token EAAPAGESECRETTOKEN123 here')));
    }
}
