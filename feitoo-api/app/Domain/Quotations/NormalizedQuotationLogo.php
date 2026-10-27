<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final readonly class NormalizedQuotationLogo
{
    private function __construct(public string $png, public int $width, public int $height) {}

    /** Accept bytes, never an uploaded filename, URL or filesystem path. */
    public static function fromBytes(string $bytes): self
    {
        $invalid = static fn () => new QuotationValidationException(['logo' => ['Use um PNG/JPEG válido de até 2 MiB e 4 milhões de pixels.']]);
        if (! extension_loaded('gd')) {
            throw new QuotationValidationException(['logo' => ['Normalização de logotipo indisponível neste ambiente.']]);
        }
        if ($bytes === '' || strlen($bytes) > QuotationLimits::MAX_LOGO_BYTES) {
            throw $invalid();
        }
        $info = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $mime !== ($info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg')
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > QuotationLimits::MAX_LOGO_PIXELS
            || ($info[2] === IMAGETYPE_PNG && self::hasAnimationChunk($bytes))) {
            throw $invalid();
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw $invalid();
        }
        $ratio = min(1, QuotationLimits::MAX_LOGO_SIDE / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $ratio));
        $height = max(1, (int) round($info[1] * $ratio));
        $target = imagecreatetruecolor($width, $height);
        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            ob_start();
            try {
                if (! imagepng($target, null, 6)) {
                    throw $invalid();
                }
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! is_string($png) || strlen($png) > QuotationLimits::MAX_LOGO_BYTES) {
                throw $invalid();
            }

            return new self($png, $width, $height);
        } finally {
            imagedestroy($source);
            imagedestroy($target);
        }
    }

    private static function hasAnimationChunk(string $bytes): bool
    {
        for ($offset = 8; $offset + 12 <= strlen($bytes);) {
            $size = unpack('Nsize', substr($bytes, $offset, 4))['size'];
            if (substr($bytes, $offset + 4, 4) === 'acTL') {
                return true;
            }
            $offset += 12 + $size;
        }

        return false;
    }
}
