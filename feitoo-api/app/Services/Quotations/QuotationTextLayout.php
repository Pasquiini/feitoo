<?php

declare(strict_types=1);

namespace App\Services\Quotations;

use Dompdf\FontMetrics;

/** Breaks text using the actual PDF font. No ellipsis, clipping or substring limits. */
final class QuotationTextLayout
{
    /** @return list<string> */
    public function lines(string $text, FontMetrics $metrics, string $font, float $width, float $size = 9.5): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text)) as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';

                continue;
            }
            $remaining = $paragraph;
            while ($remaining !== '') {
                $low = 1;
                $high = mb_strlen($remaining);
                $fit = 0;
                while ($low <= $high) {
                    $mid = intdiv($low + $high, 2);
                    if ($metrics->getTextWidth(mb_substr($remaining, 0, $mid), $font, $size) <= $width) {
                        $fit = $mid;
                        $low = $mid + 1;
                    } else {
                        $high = $mid - 1;
                    }
                }
                $fit = max(1, $fit);
                $part = mb_substr($remaining, 0, $fit);
                if ($fit < mb_strlen($remaining)) {
                    $space = mb_strrpos($part, ' ');
                    if ($space !== false && $space > 0) {
                        $fit = $space + 1;
                        $part = mb_substr($remaining, 0, $fit);
                    }
                }
                $lines[] = $part;
                $remaining = mb_substr($remaining, $fit);
            }
        }

        return $lines;
    }
}
