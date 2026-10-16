<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingFileError: string
{
    case DocxInvalid = 'docx_invalid';
    case DocxTooLarge = 'docx_too_large';
    case DocxCorrupted = 'docx_corrupted';
    case DocxArchiveUnsafe = 'docx_archive_unsafe';
    case ConverterUnavailable = 'document_converter_unavailable';
    case ConversionTimeout = 'document_conversion_timeout';
    case ConversionFailed = 'document_conversion_failed';
    case PdfOutputInvalid = 'pdf_output_invalid';
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
            self::DocxInvalid => 'Envie um arquivo DOCX válido.',
            self::DocxTooLarge => 'O documento excede o tamanho máximo permitido.',
            self::DocxCorrupted => 'Não conseguimos abrir este documento. Verifique se o arquivo DOCX não está corrompido.',
            self::DocxArchiveUnsafe => 'Este DOCX contém uma estrutura insegura, recursos não suportados ou excede os limites de expansão.',
            self::ConverterUnavailable => 'A conversão de documentos está temporariamente indisponível.',
            self::ConversionTimeout => 'A conversão demorou mais que o esperado. Tente novamente.',
            self::ConversionFailed => 'Não foi possível converter este documento para PDF.',
            self::PdfOutputInvalid => 'Não foi possível gerar um PDF válido deste documento.',
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
