<?php

namespace App\Services\Engage;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks a trusted timestamp authority (RFC 3161) to attest that a hash existed at a time. Only the SHA-256 digest is
 * sent, never any content. Off unless ENGAGE_TSA_URL is set; a failure never blocks completion, it is recorded instead.
 */
class Rfc3161Client
{
    /** @return array{token: string, authority: string}|null base64 of the whole TimeStampResp */
    public function stamp(string $sha256Hex): ?array
    {
        $url = (string) config('engage.signing.tsa_url');
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('engage.signing.tsa_timeout'))
                ->withHeaders(['Content-Type' => 'application/timestamp-query', 'Accept' => 'application/timestamp-reply'])
                ->withBody($this->request($sha256Hex), 'application/timestamp-query')
                ->post($url);

            if (! $response->successful() || ! $this->granted($response->body())) {
                return null;
            }

            return ['token' => base64_encode($response->body()), 'authority' => $url];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** DER TimeStampReq for a SHA-256 digest, with a random nonce and certReq set. */
    public function request(string $sha256Hex): string
    {
        $digest = hex2bin($sha256Hex);
        $algorithm = "\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00";
        $imprint = $this->seq($algorithm."\x04\x20".$digest);
        $nonce = random_bytes(8);
        $nonce[0] = chr(ord($nonce[0]) & 0x7F);

        return $this->seq("\x02\x01\x01".$imprint."\x02\x08".$nonce."\x01\x01\xff");
    }

    /** PKIStatus 0 (granted) or 1 (granted with modifications). */
    public function granted(string $der): bool
    {
        // SEQUENCE { SEQUENCE { INTEGER status ...
        $i = 0;
        if (! isset($der[$i]) || $der[$i] !== "\x30") {
            return false;
        }
        $i += 1 + $this->lengthBytes($der, $i + 1);
        if (! isset($der[$i]) || $der[$i] !== "\x30") {
            return false;
        }
        $i += 1 + $this->lengthBytes($der, $i + 1);
        if (! isset($der[$i], $der[$i + 1], $der[$i + 2]) || $der[$i] !== "\x02" || $der[$i + 1] !== "\x01") {
            return false;
        }

        return in_array(ord($der[$i + 2]), [0, 1], true);
    }

    private function seq(string $body): string
    {
        $len = strlen($body);
        if ($len < 128) {
            return "\x30".chr($len).$body;
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return "\x30".chr(0x80 | strlen($bytes)).$bytes.$body;
    }

    /** Number of bytes the length field takes, starting at $offset. */
    private function lengthBytes(string $der, int $offset): int
    {
        $first = ord($der[$offset] ?? "\x00");

        return $first < 128 ? 1 : 1 + ($first & 0x7F);
    }
}
