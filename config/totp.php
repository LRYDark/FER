<?php
/**
 * TOTP (RFC 6238) — Time-based One-Time Password
 * Compatible Google Authenticator, Authy, Microsoft Authenticator, etc.
 */
class TOTP
{
    public static function generateSecret(int $length = 32): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    public static function getQRUri(string $secret, string $email, string $issuer = 'Forbach en Rose'): string
    {
        $label = rawurlencode($issuer . ':' . $email);
        return 'otpauth://totp/' . $label
             . '?secret='    . rawurlencode($secret)
             . '&issuer='    . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Returns the matched counter (int) on success, false on failure.
     * Caller should store the counter and reject any future code with counter <= stored value.
     */
    public static function verify(string $secret, string $code, int $window = 1): int|false
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $keyBytes = self::base32Decode($secret);
        if ($keyBytes === '') return false;
        $ts = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($keyBytes, $ts + $i), $code)) return $ts + $i;
        }
        return false;
    }

    private static function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter
        $msg  = pack('NN', 0, $counter);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off  = ord($hash[19]) & 0x0f;
        $code = (
            ((ord($hash[$off])     & 0x7f) << 24) |
            ((ord($hash[$off + 1]) & 0xff) << 16) |
            ((ord($hash[$off + 2]) & 0xff) <<  8) |
             (ord($hash[$off + 3]) & 0xff)
        ) % 1_000_000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $input): string
    {
        static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input   = strtoupper(rtrim($input, '='));
        $output  = '';
        $buffer  = 0;
        $bitsLeft = 0;
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) continue;
            $buffer    = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $output;
    }
}
