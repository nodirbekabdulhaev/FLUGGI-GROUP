<?php

namespace Tests\Unit\Push;

use App\Services\Push\WebPushCrypto as C;
use PHPUnit\Framework\TestCase;

/** Web Push без библиотек: шифрование RFC 8291 (тестовый вектор из раздела 5 RFC) и подпись VAPID ES256. */
class WebPushCryptoTest extends TestCase
{
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';

    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    public function test_rfc8291_vector_encrypts_exactly_and_decrypts(): void
    {
        $body = C::encrypt(
            'When I grow up, I want to be a watermelon',
            C::unb64(self::UA_PUBLIC),
            C::unb64(self::AUTH),
            C::unb64('DGv6ra1nlYgDCS1FRnbzlw'),
            ['public' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', 'private' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'],
        );
        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            C::b64($body),
        );
        $this->assertSame('When I grow up, I want to be a watermelon', C::decrypt($body, ['public' => self::UA_PUBLIC, 'private' => self::UA_PRIVATE], C::unb64(self::AUTH)));
    }

    public function test_random_keys_round_trip_and_oversized_payload_rejected(): void
    {
        $ua = C::generateKeys();
        $auth = random_bytes(16);
        $text = json_encode(['title' => 'Новый лид', 'body' => str_repeat('я', 500)], JSON_UNESCAPED_UNICODE);
        $body = C::encrypt($text, C::unb64($ua['public']), $auth);
        $this->assertSame($text, C::decrypt($body, $ua, $auth));
        $this->expectException(\RuntimeException::class);
        C::encrypt(str_repeat('x', C::MAX_PLAINTEXT + 1), C::unb64($ua['public']), $auth);
    }

    public function test_vapid_jwt_is_valid_es256_with_audience_of_endpoint_origin(): void
    {
        $k = C::generateKeys();
        $header = C::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', 'mailto:ceo@fluggi.uz', $k['public'], $k['private'], 1_900_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k='.preg_quote($k['public'], '/').'$/', $header);
        preg_match('/t=([^,]+)/', $header, $m);
        [$h, $c, $s] = explode('.', $m[1]);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(C::unb64($h), true));
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_900_000_000, 'sub' => 'mailto:ceo@fluggi.uz'], json_decode(C::unb64($c), true));
        $this->assertSame(64, strlen(C::unb64($s)));
        $this->assertSame(1, openssl_verify("$h.$c", C::rawToDer(C::unb64($s)), C::publicKey(C::unb64($k['public'])), OPENSSL_ALGO_SHA256));
    }
}
