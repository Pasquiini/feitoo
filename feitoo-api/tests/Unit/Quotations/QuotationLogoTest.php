<?php

declare(strict_types=1);

namespace Tests\Unit\Quotations;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\QuotationValidationException;
use App\Support\Quotations\QuotationDemoFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotationLogoTest extends TestCase
{
    public function test_normalizes_png_and_jpeg_and_preserves_alpha_without_metadata(): void
    {
        foreach (['png', 'jpeg'] as $format) {
            $bytes = QuotationDemoFixtures::logo($format);
            $metadata = 'PRIVATE-METADATA-MUST-DISAPPEAR';
            if ($format === 'png') {
                $chunk = 'Comment'."\0".$metadata;
                $bytes = substr($bytes, 0, 33).pack('N', strlen($chunk)).'tEXt'.$chunk.pack('N', crc32('tEXt'.$chunk)).substr($bytes, 33);
            } else {
                $bytes = substr($bytes, 0, 2)."\xff\xfe".pack('n', strlen($metadata) + 2).$metadata.substr($bytes, 2);
            }
            $logo = NormalizedQuotationLogo::fromBytes($bytes);
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $logo->png);
            $this->assertStringNotContainsString($metadata, $logo->png);
            $this->assertSame([240, 100], [$logo->width, $logo->height]);
            $image = imagecreatefromstring($logo->png);
            $this->assertSame($format === 'png' ? 127 : 0, (imagecolorat($image, 0, 0) >> 24) & 0x7F);
            imagedestroy($image);
        }
    }

    public function test_resizes_large_logo_within_pixel_limit(): void
    {
        $image = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $logo = NormalizedQuotationLogo::fromBytes($bytes);
        $this->assertSame([1200, 600], [$logo->width, $logo->height]);
    }

    public static function unsafeLogos(): iterable
    {
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'url' => ['https://example.test/logo.png'];
        yield 'path' => ['C:/private/logo.png'];
        yield 'wrong bytes' => ["\x89PNG\r\n\x1a\ninvalid"];
        yield 'excess bytes' => [str_repeat('x', 2097153)];
        $ihdr = pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
        yield 'excess dimensions before decode' => ["\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr))];
    }

    #[DataProvider('unsafeLogos')]
    public function test_rejects_unsafe_logos(string $bytes): void
    {
        $this->expectException(QuotationValidationException::class);
        NormalizedQuotationLogo::fromBytes($bytes);
    }
}
