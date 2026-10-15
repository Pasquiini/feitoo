<?php

namespace App\Rules;

use App\Support\Xml\InvalidXml;
use App\Support\Xml\SecureXml;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/** Allows bounded malformed XML text to be returned in the problems folder, never DTD/entities. */
class SafeOrganizerXml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() <= 0
            || $value->getSize() > config('feitoo.uploads.max_file_size_kb') * 1024) {
            $fail('O arquivo está vazio ou excede o tamanho permitido.');

            return;
        }
        $contents = file_get_contents($value->getRealPath());
        // Malformed recovery is limited to UTF-8 XML text; binary files and archives are rejected.
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")
            || ! preg_match('/\A(?:\xEF\xBB\xBF)?\s*</', $contents)) {
            $fail('Envie conteúdo XML em UTF-8, sem compactação.');

            return;
        }
        try {
            app(SecureXml::class)->validate($contents, allowMalformed: true);
        } catch (InvalidXml) {
            $fail('XML com recursos proibidos ou excedendo os limites de segurança.');
        }
    }
}
