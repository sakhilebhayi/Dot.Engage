<?php

namespace App\Services\Engage;

use RuntimeException;

/**
 * Dot.Engage's own attestation over an evidence record: an Ed25519 signature made with the platform's key.
 *
 * This proves that the record was produced by this platform and has not changed since. It is NOT a qualified or
 * advanced electronic signature and says nothing about who the signers are beyond what the record itself states;
 * stronger assurance needs a certificate from an accredited trust service provider.
 */
class EvidenceSeal
{
    /** @return array{public_key: string, signature: string} hex public key, base64 signature */
    public function seal(string $evidenceSha256Hex): array
    {
        $pair = $this->keypair();
        $signature = sodium_crypto_sign_detached(hex2bin($evidenceSha256Hex), sodium_crypto_sign_secretkey($pair));

        return ['public_key' => bin2hex(sodium_crypto_sign_publickey($pair)), 'signature' => base64_encode($signature)];
    }

    public function verify(string $evidenceSha256Hex, string $signatureB64, string $publicKeyHex): bool
    {
        $sig = base64_decode($signatureB64, true);
        $key = @hex2bin($publicKeyHex);
        $msg = @hex2bin($evidenceSha256Hex);
        if ($sig === false || $key === false || $msg === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $msg, $key);
    }

    public function publicKeyHex(): string
    {
        return bin2hex(sodium_crypto_sign_publickey($this->keypair()));
    }

    private function keypair(): string
    {
        if (! function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new RuntimeException('The sodium extension is required to seal evidence records.');
        }

        $configured = (string) config('engage.signing.seal_seed', '');
        if ($configured !== '') {
            $seed = base64_decode($configured, true);
            if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                throw new RuntimeException('ENGAGE_SEAL_SEED must be 32 random bytes, base64 encoded.');
            }
        } else {
            $appKey = (string) config('app.key');
            $appKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;
            $seed = sodium_crypto_generichash('dot-engage evidence seal v1', (string) $appKey, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        }

        return sodium_crypto_sign_seed_keypair($seed);
    }
}
