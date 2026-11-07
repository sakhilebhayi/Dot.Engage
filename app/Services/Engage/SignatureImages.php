<?php

namespace App\Services\Engage;

use InvalidArgumentException;

/**
 * Signature images come from a browser and are never trusted as sent. They are decoded, size-checked and written
 * out again as a plain PNG, which drops anything else hidden in the file.
 */
class SignatureImages
{
    public const MAX_BYTES = 400_000;

    public const MAX_SIDE = 2400;

    /** @return string PNG bytes */
    public function clean(string $dataUri): string
    {
        if (! preg_match('#^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUri, $m)) {
            throw new InvalidArgumentException('That signature image is not in a supported format.');
        }
        $raw = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That signature image is too large or damaged.');
        }

        $info = @getimagesizefromstring($raw);
        if (! $info || $info[0] < 20 || $info[1] < 10 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new InvalidArgumentException('That signature image has an unsupported size.');
        }

        $img = @imagecreatefromstring($raw);
        if (! $img) {
            throw new InvalidArgumentException('That signature image could not be read.');
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagealphablending($out, true);
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        imagesavealpha($out, true);

        ob_start();
        imagepng($out, null, 6);
        $png = (string) ob_get_clean();

        if ($png === '' || $this->isBlank($out)) {
            throw new InvalidArgumentException('The signature is empty. Draw, type or upload your signature.');
        }

        return $png;
    }

    /** True when every pixel is transparent or white: nothing was drawn. */
    private function isBlank(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $stepX = max(1, intdiv($w, 120));
        $stepY = max(1, intdiv($h, 60));
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($a < 100 && ($r < 235 || $g < 235 || $b < 235)) {
                    return false;
                }
            }
        }

        return true;
    }
}
