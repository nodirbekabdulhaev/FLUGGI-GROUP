<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * Web Push без сторонних библиотек (на хостинге нет ext-gmp): только openssl.
 *  - шифрование сообщения — RFC 8291 (aes128gcm, RFC 8188), одна запись;
 *  - подпись VAPID — RFC 8292, JWT ES256 (подпись ECDSA P-256, DER → r||s).
 * Ключи P-256 хранятся в base64url: открытый — 65 байт (0x04||X||Y), закрытый — 32 байта (d).
 */
final class WebPushCrypto
{
    /** SubjectPublicKeyInfo (id-ecPublicKey, prime256v1) до точки 0x04||X||Y. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Размер записи aes128gcm (одна запись на сообщение). */
    public const RECORD_SIZE = 4096;

    /** Наибольший размер открытого текста, который помещается в одну запись. */
    public const MAX_PLAINTEXT = self::RECORD_SIZE - 16 - 1 - 86;

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        $bin = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($bin === false) {
            throw new RuntimeException('Invalid base64url');
        }

        return $bin;
    }

    /**
     * Новая пара ключей P-256.
     *
     * @return array{public:string, private:string} base64url
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('openssl: cannot create EC key');
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public' => self::b64("\x04".self::pad($ec['x']).self::pad($ec['y'])),
            'private' => self::b64(self::pad($ec['d'])),
        ];
    }

    private static function pad(string $bin): string
    {
        return str_pad($bin, 32, "\0", STR_PAD_LEFT);
    }

    /** Открытый ключ (0x04||X||Y, 65 байт) → ключ openssl. */
    public static function publicKey(string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Invalid P-256 public key');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Invalid P-256 public key');
        }

        return $key;
    }

    /** Закрытый ключ (d, 32 байта) и его открытый ключ → ключ openssl (SEC1 ECPrivateKey). */
    public static function privateKey(string $d, string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($d) !== 32 || strlen($point) !== 65) {
            throw new RuntimeException('Invalid P-256 private key');
        }
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$point;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Invalid P-256 private key');
        }

        return $key;
    }

    /** ECDH: общий секрет (координата X, 32 байта). */
    public static function ecdh(\OpenSSLAsymmetricKey $private, string $peerPoint): string
    {
        $secret = openssl_pkey_derive(self::publicKey($peerPoint), $private, 32);
        if ($secret === false) {
            throw new RuntimeException('openssl: ECDH failed');
        }

        return self::pad($secret);
    }

    /**
     * Шифрование тела push-сообщения (RFC 8291). $salt и ключи отправителя — только для проверки
     * по тестовым векторам RFC; в работе создаются заново для каждого сообщения.
     *
     * @param  string  $uaPublic  p256dh подписки (65 байт)
     * @param  string  $authSecret  auth подписки (16 байт)
     * @param  array{public:string, private:string}|null  $sender  base64url-ключи отправителя
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, ?array $sender = null): string
    {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new RuntimeException('Push payload too large');
        }
        $salt ??= random_bytes(16);
        $sender ??= self::generateKeys();
        $asPublic = self::unb64($sender['public']);
        $asPrivate = self::privateKey(self::unb64($sender['private']), $asPublic);

        $ecdh = self::ecdh($asPrivate, $uaPublic);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Последняя (единственная) запись: разделитель 0x02 без дополнения
        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('openssl: AES-GCM failed');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$cipher.$tag;
    }

    /**
     * Расшифровка на стороне получателя (RFC 8291) — для проверки и тестов.
     *
     * @param  array{public:string, private:string}  $ua  base64url-ключи подписки
     */
    public static function decrypt(string $body, array $ua, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $data = substr($body, 21 + $idLen);
        $uaPublic = self::unb64($ua['public']);
        $ecdh = self::ecdh(self::privateKey(self::unb64($ua['private']), $uaPublic), $asPublic);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($data, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($data, -16));
        if ($plain === false) {
            throw new RuntimeException('Push decrypt failed');
        }

        return substr(rtrim($plain, "\0"), 0, -1);
    }

    /**
     * Заголовок Authorization для VAPID (RFC 8292): «vapid t=<JWT>, k=<открытый ключ>».
     */
    public static function vapidAuthorization(string $endpoint, string $subject, string $publicKey, string $privateKey, ?int $expires = null): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode(['aud' => $aud, 'exp' => $expires ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input = $header.'.'.$claims;
        $point = self::unb64($publicKey);
        $der = '';
        if (! openssl_sign($input, $der, self::privateKey(self::unb64($privateKey), $point), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl: ES256 sign failed');
        }

        return 'vapid t='.$input.'.'.self::b64(self::derToRaw($der)).', k='.$publicKey;
    }

    /** Подпись ECDSA DER (SEQUENCE {INTEGER r, INTEGER s}) → r||s по 32 байта (JWS ES256). */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7F;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= self::pad($int);
            $pos += 2 + $len;
        }

        return $out;
    }

    /** r||s → DER (для проверки подписи openssl_verify). */
    public static function rawToDer(string $raw): string
    {
        $ints = '';
        foreach (str_split($raw, 32) as $part) {
            $part = ltrim($part, "\0");
            if ($part === '' || ord($part[0]) & 0x80) {
                $part = "\0".$part;
            }
            $ints .= "\x02".chr(strlen($part)).$part;
        }

        return "\x30".chr(strlen($ints)).$ints;
    }
}
