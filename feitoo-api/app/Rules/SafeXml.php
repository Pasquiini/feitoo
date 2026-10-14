<?php

namespace App\Rules;

use App\Support\Xml\InvalidXml;
use App\Support\Xml\SecureXml;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeXml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie um arquivo XML válido.');

            return;
        }
        $size = $value->getSize();
        if ($size === false || $size === 0 || $size > config('feitoo.uploads.max_file_size_kb') * 1024) {
            $fail('O arquivo está vazio ou excede o tamanho permitido.');

            return;
        }
        if (! in_array($value->getMimeType(), ['application/xml', 'text/xml', 'text/plain'], true)) {
            $fail('O conteúdo do arquivo não é XML aceitável.');

            return;
        }
        $contents = file_get_contents($value->getRealPath());
        if ($contents === false) {
            $fail('Não foi possível validar o XML.');

            return;
        }
        try {
            (new SecureXml)->validate($contents);
        } catch (InvalidXml) {
            $fail('XML inválido ou excedendo os limites de segurança.');
        }
    }
}
