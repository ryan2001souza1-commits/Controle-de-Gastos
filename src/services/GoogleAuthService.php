<?php
/**
 * GoogleAuthService — valida ID Token do Google e troca Authorization Code por tokens.
 *
 * Variáveis de ambiente (Vercel → Settings → Environment Variables):
 *   GOOGLE_CLIENT_ID     = client ID do OAuth Web application
 *   GOOGLE_CLIENT_SECRET = client secret (NUNCA expor ao frontend)
 *
 * Fluxo Authorization Code (recomendado pelo Google para web):
 *   1) Frontend redireciona usuário para Google (com client_id, redirect_uri, scope=openid email profile)
 *   2) Google redireciona de volta para /index.php?action=google-callback&code=...
 *   3) Backend troca code -> tokens via POST https://oauth2.googleapis.com/token
 *   4) Backend valida o id_token (assinatura, issuer, audience, exp, nonce)
 *   5) Se válido: identifica usuário pelo sub, faz login ou cria conta
 */
class GoogleAuthService
{
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->clientId     = $this->env('GOOGLE_CLIENT_ID');
        $this->clientSecret = $this->env('GOOGLE_CLIENT_SECRET');
    }

    public function isConfigured(): bool
    {
        // Re-lê a cada requisição (no serverless o construtor roda no cold start;
        // se a env var foi alterada via redeploy, o objeto pode estar stale).
        $cid = $this->env('GOOGLE_CLIENT_ID');
        $cs  = $this->env('GOOGLE_CLIENT_SECRET');
        return $cid !== '' && $cs !== '';
    }

    public function getClientId(): string
    {
        $v = $this->env('GOOGLE_CLIENT_ID');
        return $v !== '' ? $v : $this->clientId;
    }

    public function getClientSecret(): string
    {
        $v = $this->env('GOOGLE_CLIENT_SECRET');
        return $v !== '' ? $v : $this->clientSecret;
    }

    /**
     * Troca o authorization code por tokens no endpoint oficial do Google.
     */
    public function exchangeCodeForTokens(string $code, string $redirectUri): ?array
    {
        $resp = $this->httpPostForm('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($resp === null) return null;
        if (!isset($resp['id_token'])) return null;
        return $resp;
    }

    /**
     * Valida o id_token: verifica assinatura (JWKS), issuer, audience, exp.
     * Retorna os claims (sub, email, email_verified, name) ou null.
     */
    public function validateIdToken(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return null;

        $header  = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $sig     = $this->base64UrlDecode($parts[2]);

        if (!is_array($header) || !is_array($payload) || $sig === '') return null;
        if (($header['alg'] ?? '') !== 'RS256') return null;
        if (($payload['iss'] ?? '') !== 'https://accounts.google.com' && ($payload['iss'] ?? '') !== 'accounts.google.com') return null;
        if (($payload['aud'] ?? '') !== $this->getClientId()) return null;
        if (!isset($payload['exp']) || time() >= (int)$payload['exp']) return null;
        if (($payload['email_verified'] ?? false) !== true) return null;

        $kid = $header['kid'] ?? null;
        if (!$kid) return null;

        $pem = $this->getGooglePublicKey($kid);
        if ($pem === null) return null;

        $signedInput = $parts[0] . '.' . $parts[1];
        $ok = openssl_verify($signedInput, $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return null;

        return $payload;
    }

    private function getGooglePublicKey(string $kid): ?string
    {
        $cacheFile = sys_get_temp_dir() . '/google_jwks.json';
        $jwks = null;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $jwks = json_decode((string)file_get_contents($cacheFile), true);
        }
        if (!is_array($jwks)) {
            $resp = $this->httpGet('https://www.googleapis.com/oauth2/v3/certs');
            $jwks = is_array($resp) ? $resp : null;
            if ($jwks !== null) {
                @file_put_contents($cacheFile, json_encode($jwks));
            }
        }
        if (!is_array($jwks) || !isset($jwks['keys'])) return null;

        foreach ($jwks['keys'] as $k) {
            if (($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA') {
                $pem = $this->jwkToPem($k);
                return $pem ?: null;
            }
        }
        return null;
    }

    private function jwkToPem(array $jwk): ?string
    {
        if (!isset($jwk['n'], $jwk['e'])) return null;
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);
        if ($n === '' || $e === '') return null;

        $modulus = $this->bigIntToBytes($n);
        $exponent = $this->bigIntToBytes($e);
        $modulus = ltrim($modulus, "\x00");
        $modulus = (strlen($modulus) % 2 === 1) ? "\x00" . $modulus : $modulus;

        $rsaPubKey = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $bitString = "\x00" . $rsaPubKey;
        $rsaPubKeyInfo = $this->asn1Sequence(
            $this->asn1Sequence(
                "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" .
                "\x05\x00"
            ) . $this->asn1BitString($bitString)
        );

        $pem  = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($rsaPubKeyInfo), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private function bigIntToBytes(string $bigInt): string
    {
        $hex = bin2hex($bigInt);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        return hex2bin($hex);
    }

    private function asn1Integer(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length === 0) return '';
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
            $length++;
        }
        return "\x02" . $this->asn1Length($length) . $bytes;
    }

    private function asn1BitString(string $bytes): string
    {
        $length = strlen($bytes);
        return "\x03" . $this->asn1Length($length) . $bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        $length = strlen($bytes);
        return "\x30" . $this->asn1Length($length) . $bytes;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlDecode(string $s): string
    {
        $r = strtr($s, '-_', '+/');
        $pad = strlen($r) % 4;
        if ($pad > 0) $r .= str_repeat('=', 4 - $pad);
        $out = base64_decode($r, true);
        return $out === false ? '' : $out;
    }

    private function httpGet(string $url): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) return null;
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : null;
    }

    private function httpPostForm(string $url, array $fields): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            error_log('[GoogleAuth] token exchange HTTP ' . $code . ' resp_bytes=' . strlen((string)$resp));
            return null;
        }
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : null;
    }

    private function env(string $key): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return (string)$v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return '';
    }
}
