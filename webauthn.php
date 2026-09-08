<?php
declare(strict_types=1);

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

class WebAuthn {
    public function __construct(
        private readonly string $rpId,
        private readonly string $rpName,
        private readonly string $origin
    ) {}

    public function generateChallenge(): string {
        $ch = base64url_encode(random_bytes(32));
        return $ch;
    }

    /** Returns ['credentialId'=>string, 'publicKey'=>string(PEM), 'signCount'=>int] */
    public function verifyRegistration(array $credential, string $challenge): array {
        $clientData = json_decode(base64url_decode($credential['response']['clientDataJSON']), true);
        if ($clientData['type'] !== 'webauthn.create') throw new \RuntimeException('Bad type');
        if ($clientData['challenge'] !== $challenge)   throw new \RuntimeException('Challenge mismatch');
        if ($clientData['origin']    !== $this->origin) throw new \RuntimeException('Origin mismatch');

        $attObj  = $this->cborDecode(base64url_decode($credential['response']['attestationObject']));
        $authData = $attObj['authData'];
        $parsed   = $this->parseAuthData($authData);

        if ($parsed['rpIdHash'] !== hash('sha256', $this->rpId, true)) throw new \RuntimeException('rpId mismatch');
        if (!($parsed['flags'] & 0x01)) throw new \RuntimeException('User not present');

        return [
            'credentialId' => base64url_encode($parsed['credentialId']),
            'publicKey'    => $this->coseToPem($parsed['coseKey']),
            'signCount'    => $parsed['signCount'],
        ];
    }

    /** Returns new signCount */
    public function verifyAssertion(array $credential, string $challenge, string $publicKeyPem, int $storedCount): int {
        $cdJson   = base64url_decode($credential['response']['clientDataJSON']);
        $authData = base64url_decode($credential['response']['authenticatorData']);
        $sig      = base64url_decode($credential['response']['signature']);

        $clientData = json_decode($cdJson, true);
        if ($clientData['type']      !== 'webauthn.get') throw new \RuntimeException('Bad type');
        if ($clientData['challenge'] !== $challenge)     throw new \RuntimeException('Challenge mismatch');
        if ($clientData['origin']    !== $this->origin)  throw new \RuntimeException('Origin mismatch');

        $rpIdHash = substr($authData, 0, 32);
        if ($rpIdHash !== hash('sha256', $this->rpId, true)) throw new \RuntimeException('rpId mismatch');

        $verifyData = $authData . hash('sha256', $cdJson, true);
        $pub = openssl_pkey_get_public($publicKeyPem);
        if ($pub === false) throw new \RuntimeException('Bad public key');
        if (openssl_verify($verifyData, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) throw new \RuntimeException('Signature failed');

        $signCount = unpack('N', substr($authData, 33, 4))[1];
        if ($storedCount > 0 && $signCount <= $storedCount) throw new \RuntimeException('Counter replay');
        return $signCount;
    }

    private function parseAuthData(string $d): array {
        $o        = 0;
        $rpIdHash = substr($d, $o, 32); $o += 32;
        $flags    = ord($d[$o]);        $o += 1;
        $signCount = unpack('N', substr($d, $o, 4))[1]; $o += 4;
        $credentialId = ''; $coseKey = [];
        if ($flags & 0x40) {
            $o += 16; // aaguid
            $cil = unpack('n', substr($d, $o, 2))[1]; $o += 2;
            $credentialId = substr($d, $o, $cil);     $o += $cil;
            $coseKey = $this->cborDecode(substr($d, $o));
        }
        return compact('rpIdHash','flags','signCount','credentialId','coseKey');
    }

    private function coseToPem(array $cose): string {
        $kty = $cose[1] ?? null;
        if ($kty === 2) {
            $x = $cose[-2]; $y = $cose[-3];
            $point = "\x04" . $x . $y;
            // SubjectPublicKeyInfo for P-256
            $alg = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
            $bs  = "\x03" . $this->asn1Len(strlen($point)+1) . "\x00" . $point;
            $seq = "\x30" . $this->asn1Len(strlen($alg.$bs)) . $alg . $bs;
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq), 64) . "-----END PUBLIC KEY-----\n";
        }
        throw new \RuntimeException('Unsupported COSE kty: '.(string)$kty);
    }

    private function asn1Len(int $n): string {
        if ($n < 128) return chr($n);
        $b = ''; $t = $n;
        while ($t > 0) { $b = chr($t & 0xff) . $b; $t >>= 8; }
        return chr(0x80 | strlen($b)) . $b;
    }

    private function cborDecode(string $data): mixed {
        $o = 0;
        return $this->cborItem($data, $o);
    }

    private function cborItem(string $d, int &$o): mixed {
        $b  = ord($d[$o++]);
        $mt = ($b >> 5) & 7;
        $ai = $b & 31;
        $v  = $this->cborLen($d, $o, $ai);
        return match($mt) {
            0 => $v,
            1 => -1 - $v,
            2 => (function() use ($d,&$o,$v) { $r=substr($d,$o,$v); $o+=$v; return $r; })(),
            3 => (function() use ($d,&$o,$v) { $r=substr($d,$o,$v); $o+=$v; return $r; })(),
            4 => (function() use ($d,&$o,$v) { $r=[]; for($i=0;$i<$v;$i++) $r[]=$this->cborItem($d,$o); return $r; })(),
            5 => (function() use ($d,&$o,$v) { $r=[]; for($i=0;$i<$v;$i++){$k=$this->cborItem($d,$o);$r[$k]=$this->cborItem($d,$o);} return $r; })(),
            7 => match($ai){20=>false,21=>true,22=>null,default=>null},
            default => null
        };
    }

    private function cborLen(string $d, int &$o, int $ai): int {
        if ($ai < 24) return $ai;
        if ($ai === 24) return ord($d[$o++]);
        if ($ai === 25) { $v=unpack('n',substr($d,$o,2))[1]; $o+=2; return $v; }
        if ($ai === 26) { $v=unpack('N',substr($d,$o,4))[1]; $o+=4; return $v; }
        if ($ai === 27) { $v=unpack('J',substr($d,$o,8))[1]; $o+=8; return $v; }
        return 0;
    }
}
