<?php
/**
 * WebAuthn / Passkeys — inscription et authentification (FIDO2, W3C WebAuthn L2)
 * Supporte EC P-256 (ES256, alg=-7) et RSA-PKCS1 (RS256, alg=-257)
 */

function webauthn_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthn_base64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

class WebAuthn
{
    private string $rpId;
    private string $rpName;

    public function __construct(string $rpId, string $rpName = 'Forbach en Rose')
    {
        $this->rpId   = $rpId;
        $this->rpName = $rpName;
    }

    // ── Inscription ──────────────────────────────────────────────────────────

    public function registrationOptions(int $userId, string $userEmail, array $existingIds = []): array
    {
        $challenge = webauthn_base64url_encode(random_bytes(32));
        $_SESSION['wa_reg_challenge'] = $challenge;

        $exclude = array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $existingIds);

        return [
            'challenge'              => $challenge,
            'rp'                     => ['id' => $this->rpId, 'name' => $this->rpName],
            'user'                   => [
                'id'          => webauthn_base64url_encode(pack('V', $userId)),
                'name'        => $userEmail,
                'displayName' => $userEmail,
            ],
            'pubKeyCredParams'        => [
                ['type' => 'public-key', 'alg' => -7],    // ES256 (P-256)
                ['type' => 'public-key', 'alg' => -257],   // RS256
            ],
            'excludeCredentials'     => $exclude,
            'authenticatorSelection' => [
                'residentKey'      => 'preferred',
                'userVerification' => 'preferred',
            ],
            'timeout'    => 60000,
            'attestation' => 'none',
        ];
    }

    public function verifyRegistration(array $response): array
    {
        $challenge = $_SESSION['wa_reg_challenge'] ?? '';
        unset($_SESSION['wa_reg_challenge']);
        if (!$challenge) throw new \RuntimeException('Session d\'inscription WebAuthn expirée.');

        // 1. Vérifier clientDataJSON
        $clientData = json_decode(webauthn_base64url_decode($response['clientDataJSON'] ?? ''), true);
        if (!$clientData) throw new \RuntimeException('clientDataJSON invalide.');
        if (($clientData['type'] ?? '') !== 'webauthn.create')
            throw new \RuntimeException('Type WebAuthn invalide.');
        if (($clientData['challenge'] ?? '') !== $challenge)
            throw new \RuntimeException('Challenge WebAuthn ne correspond pas.');

        // 2. Décoder l'attestationObject (CBOR)
        $attestation = $this->cborDecode(webauthn_base64url_decode($response['attestationObject'] ?? ''));
        $authData    = $attestation['authData'] ?? '';
        if (strlen($authData) < 37) throw new \RuntimeException('authData trop court.');

        // 3. Vérifier rpIdHash
        if (substr($authData, 0, 32) !== hash('sha256', $this->rpId, true))
            throw new \RuntimeException('rpId hash ne correspond pas.');

        $flags = ord($authData[32]);
        if (!($flags & 0x01)) throw new \RuntimeException('Utilisateur non présent (UP=0).');

        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 4. Extraire les données du credential (AT flag = bit 6)
        if (!($flags & 0x40)) throw new \RuntimeException('Pas de données attestedCredentialData.');

        $offset = 37 + 16; // skip aaguid (16 bytes)
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset   += 2;
        $credentialId       = substr($authData, $offset, $credIdLen);
        $offset            += $credIdLen;
        $credentialPublicKey = substr($authData, $offset);

        return [
            'credential_id' => webauthn_base64url_encode($credentialId),
            'public_key'    => base64_encode($credentialPublicKey), // CBOR brut stocké en base64
            'sign_count'    => $signCount,
        ];
    }

    // ── Authentification ─────────────────────────────────────────────────────

    public function authOptions(array $credentialIds): array
    {
        $challenge = webauthn_base64url_encode(random_bytes(32));
        $_SESSION['wa_auth_challenge'] = $challenge;

        $allow = array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $credentialIds);

        return [
            'challenge'        => $challenge,
            'rpId'             => $this->rpId,
            'allowCredentials' => $allow,
            'userVerification' => 'preferred',
            'timeout'          => 60000,
        ];
    }

    public function verifyAuthentication(array $response, string $storedPublicKey, int $storedSignCount): int
    {
        $challenge = $_SESSION['wa_auth_challenge'] ?? '';
        unset($_SESSION['wa_auth_challenge']);
        if (!$challenge) throw new \RuntimeException('Session d\'authentification WebAuthn expirée.');

        // 1. Vérifier clientDataJSON
        $clientData = json_decode(webauthn_base64url_decode($response['clientDataJSON'] ?? ''), true);
        if (!$clientData) throw new \RuntimeException('clientDataJSON invalide.');
        if (($clientData['type'] ?? '') !== 'webauthn.get')
            throw new \RuntimeException('Type WebAuthn invalide.');
        if (($clientData['challenge'] ?? '') !== $challenge)
            throw new \RuntimeException('Challenge WebAuthn ne correspond pas.');

        // 2. Parser l'authenticatorData
        $authData = webauthn_base64url_decode($response['authenticatorData'] ?? '');
        if (strlen($authData) < 37) throw new \RuntimeException('authenticatorData trop court.');

        if (substr($authData, 0, 32) !== hash('sha256', $this->rpId, true))
            throw new \RuntimeException('rpId hash ne correspond pas.');

        $flags = ord($authData[32]);
        if (!($flags & 0x01)) throw new \RuntimeException('Utilisateur non présent (UP=0).');

        $newSignCount = unpack('N', substr($authData, 33, 4))[1];
        if ($newSignCount > 0 && $storedSignCount > 0 && $newSignCount <= $storedSignCount)
            throw new \RuntimeException('Compteur de signature invalide — possible attaque replay.');

        // 3. Vérifier la signature
        $clientDataHash = hash('sha256', webauthn_base64url_decode($response['clientDataJSON']), true);
        $signedData     = $authData . $clientDataHash;
        $signature      = webauthn_base64url_decode($response['signature'] ?? '');

        $coseKey = $this->cborDecode(base64_decode($storedPublicKey));
        $pem     = $this->coseToPem($coseKey);

        if (openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1)
            throw new \RuntimeException('Signature WebAuthn invalide.');

        return $newSignCount;
    }

    // ── Clé COSE → PEM ───────────────────────────────────────────────────────

    private function coseToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;

        if ($kty === 2) { // EC (P-256)
            $x   = str_pad($cose[-2] ?? '', 32, "\x00", STR_PAD_LEFT);
            $y   = str_pad($cose[-3] ?? '', 32, "\x00", STR_PAD_LEFT);
            $der = $this->buildEcP256Der($x, $y);
        } elseif ($kty === 3) { // RSA
            $n   = $cose[-1] ?? '';
            $e   = $cose[-2] ?? '';
            $der = $this->buildRsaDer($n, $e);
        } else {
            throw new \RuntimeException('Type de clé COSE non supporté: ' . $kty);
        }

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    private function buildEcP256Der(string $x, string $y): string
    {
        $algoOid  = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // id-ecPublicKey
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1
        $algoSeq  = "\x30" . $this->derLen(strlen($algoOid) + strlen($curveOid)) . $algoOid . $curveOid;
        $point    = "\x04" . $x . $y;
        $bitStr   = "\x03" . $this->derLen(1 + strlen($point)) . "\x00" . $point;
        return "\x30" . $this->derLen(strlen($algoSeq) + strlen($bitStr)) . $algoSeq . $bitStr;
    }

    private function buildRsaDer(string $n, string $e): string
    {
        // Garantir entier positif (DER)
        if (strlen($n) && (ord($n[0]) & 0x80)) $n = "\x00" . $n;
        if (strlen($e) && (ord($e[0]) & 0x80)) $e = "\x00" . $e;

        $nInt    = "\x02" . $this->derLen(strlen($n)) . $n;
        $eInt    = "\x02" . $this->derLen(strlen($e)) . $e;
        $rsaKey  = "\x30" . $this->derLen(strlen($nInt) + strlen($eInt)) . $nInt . $eInt;
        $bitStr  = "\x03" . $this->derLen(1 + strlen($rsaKey)) . "\x00" . $rsaKey;
        $rsaOid  = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption + NULL
        $algoSeq = "\x30" . $this->derLen(strlen($rsaOid)) . $rsaOid;
        return "\x30" . $this->derLen(strlen($algoSeq) + strlen($bitStr)) . $algoSeq . $bitStr;
    }

    private function derLen(int $len): string
    {
        if ($len < 0x80) return chr($len);
        $buf = '';
        for ($tmp = $len; $tmp > 0; $tmp >>= 8) $buf = chr($tmp & 0xff) . $buf;
        return chr(0x80 | strlen($buf)) . $buf;
    }

    // ── Décodeur CBOR minimal ─────────────────────────────────────────────────

    public function cborDecode(string $data): mixed
    {
        $pos = 0;
        return $this->cborItem($data, $pos);
    }

    private function cborItem(string $data, int &$pos): mixed
    {
        if ($pos >= strlen($data)) throw new \RuntimeException('CBOR: fin prématurée des données.');

        $byte = ord($data[$pos++]);
        $mt   = $byte >> 5;
        $ai   = $byte & 0x1f;
        $val  = $this->cborArg($data, $pos, $ai);

        switch ($mt) {
            case 0: return $val; // uint
            case 1: return -1 - $val; // nint
            case 2: // bstr
                $s = substr($data, $pos, $val); $pos += $val; return $s;
            case 3: // tstr
                $s = substr($data, $pos, $val); $pos += $val; return $s;
            case 4: // array
                $arr = [];
                for ($i = 0; $i < $val; $i++) $arr[] = $this->cborItem($data, $pos);
                return $arr;
            case 5: // map
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = $this->cborItem($data, $pos);
                    $map[$k] = $this->cborItem($data, $pos);
                }
                return $map;
            case 6: // tagged — ignorer le tag
                return $this->cborItem($data, $pos);
            case 7:
                if ($ai === 20) return false;
                if ($ai === 21) return true;
                if ($ai === 22) return null;
                return $val;
        }
        throw new \RuntimeException('CBOR: type majeur inconnu: ' . $mt);
    }

    private function cborArg(string $data, int &$pos, int $ai): int
    {
        if ($ai < 24) return $ai;
        if ($ai === 24) return ord($data[$pos++]);
        if ($ai === 25) { $v = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; return $v; }
        if ($ai === 26) { $v = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; return $v; }
        if ($ai === 27) {
            // 64-bit big-endian (PHP 5.6.3+ avec pack 'J' ou fallback)
            $hi = unpack('N', substr($data, $pos, 4))[1];
            $lo = unpack('N', substr($data, $pos + 4, 4))[1];
            $pos += 8;
            return ($hi << 32) | $lo;
        }
        throw new \RuntimeException('CBOR: info additionnelle non supportée: ' . $ai);
    }
}

/**
 * Retourne le rpId WebAuthn (hostname sans port).
 */
function getWebAuthnRpId(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $pos  = strrpos($host, ':');
    return $pos !== false ? substr($host, 0, $pos) : $host;
}
