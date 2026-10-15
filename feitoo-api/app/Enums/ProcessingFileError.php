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
    case InvalidProtocol = 'invalid_protocol';
    case DuplicateDocument = 'duplicate_document';
    case PdfError = 'pdf_error';
    case AnalysisCapacity = 'analysis_capacity';

    public function message(): string
    {
        return match ($this) {
            self::AnalysisCapacity => 'O arquivo ultrapassa a capacidade de itens/dados da análise. Divida o lote ou envie XMLs menores.',
            self::InvalidProtocol => 'Protocolo de autorização ausente ou inconsistente. Envie o XML processado completo.',
            self::DuplicateDocument => 'NF-e repetida neste lote. O documento não consome o limite novamente.',
            self::PdfError => 'Não foi possível gerar o DANFE deste XML.',
            self::InvalidXml => 'XML inválido ou inconsistente.',
            self::UnsupportedDocument => 'Documento não suportado. Envie uma NF-e modelo 55.',
            self::MissingRequiredField => 'NF-e incompleta: faltam campos obrigatórios.',
            self::ParseError => 'Não foi possível interpretar este documento.',
            self::StorageError => 'Não foi possível ler o arquivo com integridade.',
        };
    }
}
