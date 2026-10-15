<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\MissingRequiredFiscalField;
use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Models\Processing;
use App\Models\ProcessingFile;
use App\Support\Xml\InvalidXml;
use App\Support\Xml\SecureXml;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class XmlOrganizerService
{
    public const DEFAULT_OPTIONS = ['criterion' => 'year_month', 'detect_duplicates' => true, 'duplicate_action' => 'separate', 'report' => true, 'standardize_names' => true];

    public const NOTICE = 'Dados extraídos do XML. Status atual na SEFAZ não consultado; assinatura digital e autorização não verificadas.';

    public function __construct(private readonly OrganizerXmlReader $reader, private readonly OrganizerNames $names, private readonly PrivateProcessingFileReader $files) {}

    /** Called under the existing Processing lock; per-file commits make retries idempotent. */
    public function processFile(Processing $processing, ProcessingFile $file): void
    {
        $meta = $processing->metadata ?? [];
        $options = array_replace(self::DEFAULT_OPTIONS, $meta['organizer_options'] ?? []);
        $rows = $meta['organizer_files'] ?? [];
        $row = array_fill_keys(['access_key', 'number', 'series', 'issued_at', 'month', 'issuer_document', 'issuer_name', 'recipient_document', 'model', 'total_value', 'archive_path', 'folder', 'final_name'], null);
        $row += ['original_name' => $file->original_name, 'checksum' => $file->checksum, 'type' => 'Outros', 'recognized' => false, 'valid' => false,
            'notes' => [], 'duplicate_kind' => null, 'duplicate_of' => null, 'key_conflict' => false, 'preservable' => false];
        $error = null;
        try {
            $contents = $this->files->read($file);
            // Defensive revalidation: even raw originals returned in the ZIP must be bounded and safe.
            app(SecureXml::class)->validate($contents, allowMalformed: true);
            $row['preservable'] = true;
            try {
                $row = array_replace($row, $this->reader->read($contents));
                if (! $row['recognized']) {
                    $error = ProcessingFileError::UnsupportedDocument;
                } elseif (! $row['valid']) {
                    $error = ProcessingFileError::InvalidXml;
                }
            } catch (InvalidXml|InvalidFiscalXml|MissingRequiredFiscalField) {
                $error = ProcessingFileError::InvalidXml;
                $row['notes'][] = 'XML malformado, incompleto ou com estrutura ambígua. Original preservado na pasta de problemas.';
            }
        } catch (Throwable $exception) {
            $error = ProcessingFileError::StorageError;
            $row['preservable'] = false;
            $row['notes'][] = 'Arquivo indisponível ou fora dos limites de segurança; não incluído no ZIP.';
            Log::warning('Organizer input unavailable.', ['processing_uuid' => $processing->uuid, 'file_uuid' => $file->uuid, 'exception_type' => $exception::class]);
        }
        if ($options['detect_duplicates']) {
            foreach ($rows as $id => &$previous) {
                if ($previous['checksum'] === $row['checksum']) {
                    $row['duplicate_kind'] = 'Conteúdo idêntico';
                    $row['duplicate_of'] ??= $id;
                } elseif ($row['access_key'] !== null && $previous['access_key'] === $row['access_key']) {
                    $row['duplicate_kind'] ??= 'Mesma chave, conteúdo diferente';
                    $row['duplicate_of'] ??= $id;
                    $row['key_conflict'] = $previous['key_conflict'] = true;
                    $previous['notes'] = array_values(array_unique([...$previous['notes'], 'Conflito: há conteúdos diferentes com a mesma chave neste lote.']));
                }
            }
            unset($previous);
            if ($row['duplicate_kind']) {
                $row['notes'][] = $row['duplicate_kind'].'. Nenhum arquivo foi descartado.';
            }
            if ($row['key_conflict']) {
                $row['notes'][] = 'Conflito: há conteúdos diferentes com a mesma chave neste lote.';
            }
        }
        if ($row['preservable']) {
            $folder = $error ? 'Arquivos_Com_Problemas/'.$row['type'] : $this->names->folder($row, $options['criterion']);
            if ($error === ProcessingFileError::UnsupportedDocument && $options['criterion'] === 'model') {
                $folder = 'Outros';
            }
            if (! $error && $row['duplicate_kind'] && $options['duplicate_action'] === 'separate') {
                $folder = 'Duplicados/'.($row['duplicate_kind'] === 'Conteúdo idêntico' ? 'Conteudo_Identico' : 'Mesma_Chave').'/'.$folder;
            }
            $row['archive_path'] = $this->names->unique($folder, $this->names->filename($row, (bool) $options['standardize_names']), $rows);
            $row['folder'] = dirname($row['archive_path']);
            $row['final_name'] = basename($row['archive_path']);
        }
        $row['status'] = $error ? 'Com problemas' : 'Organizado';
        $row['notes'] = array_values(array_unique($row['notes']));
        $rows[$file->uuid] = $row;
        $meta['organizer_files'] = $rows;
        $processing->update(['metadata' => $meta]);
        $file->update(['status' => $error ? ProcessingFileStatus::Failed : ProcessingFileStatus::Completed,
            'processed_at' => now(), 'error_code' => $error,
            'error_message' => $error ? implode(' ', $row['notes']) : null]);
    }

    public function summary(Processing $processing): array
    {
        $rows = $processing->metadata['organizer_files'] ?? [];
        $summary = ['total_files' => $processing->files_count, 'organized_count' => 0, 'duplicates_count' => 0, 'invalid_count' => 0,
            'suppliers_count' => 0, 'preserved_count' => 0, 'conflicting_keys_count' => 0, 'warnings_count' => 0, 'documents_by_month' => [],
            'duplicate_detection' => (bool) ($processing->metadata['organizer_options']['detect_duplicates'] ?? true), 'notice' => self::NOTICE];
        $suppliers = $conflicts = [];
        foreach ($rows as $row) {
            $ok = $row['status'] === 'Organizado';
            $summary[$ok ? 'organized_count' : 'invalid_count']++;
            if ($row['duplicate_kind']) {
                $summary['duplicates_count']++;
            }
            if ($row['archive_path']) {
                $summary['preserved_count']++;
            }
            if ($row['notes']) {
                $summary['warnings_count']++;
            }
            if ($row['key_conflict']) {
                $conflicts[$row['access_key']] = true;
            }
            if ($ok && ($row['issuer_document'] || $row['issuer_name'])) {
                $suppliers[$row['issuer_document'] ?? $row['issuer_name']] = true;
            }
            if ($ok && $row['month']) {
                $summary['documents_by_month'][$row['month']] = ($summary['documents_by_month'][$row['month']] ?? 0) + 1;
            }
        }
        ksort($summary['documents_by_month']);
        $summary['suppliers_count'] = count($suppliers);
        $summary['conflicting_keys_count'] = count($conflicts);

        return $summary;
    }

    /** The existing job holds the parent lock through final artifact generation. */
    public function generate(Processing $processing): void
    {
        if ($processing->output()->exists()) {
            return;
        }
        $rows = $processing->metadata['organizer_files'] ?? [];
        $options = array_replace(self::DEFAULT_OPTIONS, $processing->metadata['organizer_options'] ?? []);
        $local = Storage::disk('local');
        $directory = 'organizer-temporary/'.$processing->uuid;
        if (! $local->makeDirectory($directory)) {
            throw new RuntimeException('Organizer temporary storage unavailable.');
        }
        $zipPath = $local->path($directory.'/archive.zip');
        $disk = Storage::disk(config('feitoo.processing.disk'));
        $outputPath = 'processings/'.$processing->uuid.'/output/organized.zip';
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Organizer ZIP unavailable.');
            }
            try {
                foreach ($processing->files()->where('kind', 'input')->whereNull('removed_at')->orderBy('id')->cursor() as $file) {
                    $row = $rows[$file->uuid] ?? throw new RuntimeException('Organizer metadata incomplete.');
                    if ($row['archive_path'] === null) {
                        continue;
                    }
                    // One bounded read at a time, disk-backed staging; ZipArchive keeps paths, not all XML strings.
                    $contents = $this->files->read($file);
                    $staged = $local->path($directory.'/'.$file->uuid.'.xml');
                    if (file_put_contents($staged, $contents) !== strlen($contents)) {
                        throw new RuntimeException('Organizer staging failed.');
                    }
                    unset($contents);
                    if (! $zip->addFile($staged, $row['archive_path'])) {
                        throw new RuntimeException('Organizer ZIP entry failed.');
                    }
                }
                if ($options['report']) {
                    $report = $local->path($directory.'/report.xlsx');
                    app(OrganizerReport::class)->generate($rows, $this->summary($processing), $report);
                    if (! $zip->addFile($report, 'Relatorios/relatorio_organizacao.xlsx')) {
                        throw new RuntimeException('Organizer report entry failed.');
                    }
                }
                if (! $zip->addFromString('LEIA-ME.txt', self::NOTICE."\nTodos os arquivos incluídos preservam seus bytes originais. Duplicados não são descartados. Consulte a pasta Arquivos_Com_Problemas e as observações do relatório, quando habilitado.")) {
                    throw new RuntimeException('Organizer notice entry failed.');
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Organizer ZIP finalization failed.');
                }
            }
            $stream = fopen($zipPath, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Organizer ZIP read failed.');
            }
            try {
                if (! $disk->put($outputPath, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Organizer ZIP storage failed.');
                }
            } finally {
                fclose($stream);
            }
            $processing->files()->create([
                'uuid' => (string) Str::uuid(), 'kind' => ProcessingFileKind::Output, 'status' => ProcessingFileStatus::Completed,
                'stored_name' => 'organized.zip', 'original_name' => 'XMLs_Organizados.zip', 'storage_disk' => config('feitoo.processing.disk'),
                'storage_path' => $outputPath, 'mime_type' => 'application/zip', 'extension' => 'zip',
                'size' => filesize($zipPath), 'checksum' => hash_file('sha256', $zipPath), 'expires_at' => $processing->expires_at, 'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $disk->delete($outputPath);
            throw $exception;
        } finally {
            $local->deleteDirectory($directory);
        }
    }
}
