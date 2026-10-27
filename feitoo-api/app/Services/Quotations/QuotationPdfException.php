<?php

declare(strict_types=1);

namespace App\Services\Quotations;

use RuntimeException;

final class QuotationPdfException extends RuntimeException
{
    // Only these fixed codes may cross the child/parent diagnostic boundary.
    public const DIAGNOSTIC_REASONS = [
        'bootstrap_failed', 'snapshot_invalid', 'document_preparation_failed', 'logo_normalization_failed',
        'render_failed', 'unsupported_character', 'page_limit_exceeded', 'pdf_invalid', 'result_write_failed',
        'execution_failed', 'execution_timeout', 'execution_terminated', 'memory_limit_exceeded',
        'cpu_limit_exceeded', 'file_size_limit_exceeded', 'cleanup_failed',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Não foi possível gerar o PDF do orçamento com segurança.');
    }
}
