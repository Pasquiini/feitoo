<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingFileError: string
{
    case InvalidXml = 'invalid_xml';
    case UnsupportedDocument = 'unsupported_document';
    case MissingRequiredField = 'missing_required_field';
    case ParseError = 'parse_error';
    case StorageError = 'storage_error';

    public function message(): string
    {
        return match ($this) {
            self::InvalidXml => 'XML inválido ou inconsistente.',
            self::UnsupportedDocument => 'Documento não suportado. Envie uma NF-e modelo 55.',
            self::MissingRequiredField => 'NF-e incompleta: faltam campos obrigatórios.',
            self::ParseError => 'Não foi possível interpretar este documento.',
            self::StorageError => 'Não foi possível ler o arquivo com integridade.',
        };
    }
}
