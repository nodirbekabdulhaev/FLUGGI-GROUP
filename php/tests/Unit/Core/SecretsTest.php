<?php

namespace Tests\Unit\Core;

use App\Support\Secrets;
use Tests\TestCase;

/** Секреты не попадают в тексты ошибок (база, интерфейс, лог). */
class SecretsTest extends TestCase
{
    public function test_tokens_in_urls_are_hidden(): void
    {
        config(['fluggi.telegram.token' => '8787:SECRET-BOT-TOKEN-VALUE', 'fluggi.meta.page_access_token' => 'EAAPAGETOKENVALUE123']);
        $s = Secrets::redact('cURL error 28 for https://api.telegram.org/bot8787345022:AAE17fNjhzNXcQ_x-y/sendVideo');
        $this->assertSame('cURL error 28 for https://api.telegram.org/bot***/sendVideo', $s);
        $this->assertSame('GET https://graph.facebook.com/v21.0/me?fields=id&access_token=*** failed',
            Secrets::redact('GET https://graph.facebook.com/v21.0/me?fields=id&access_token=EAAB12x_y failed'));
        $this->assertSame('Authorization: OAuth ***', Secrets::redact('Authorization: OAuth EAABxxxxxxxxxxxxxxxxxxxxxxxx'));
        $this->assertSame('token *** and ***', Secrets::redact('token 8787:SECRET-BOT-TOKEN-VALUE and EAAPAGETOKENVALUE123'));
        $this->assertSame('', Secrets::redact(null));
    }
}
