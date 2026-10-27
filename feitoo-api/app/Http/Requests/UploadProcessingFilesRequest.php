<?php

namespace App\Http\Requests;

use App\Enums\ProcessingTool;
use App\Rules\SafeOrganizerXml;
use App\Rules\SafeXml;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class UploadProcessingFilesRequest extends FormRequest
{
    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = $this->file('files', []);
            if (is_array($files) && array_sum(array_map(fn ($file) => $file instanceof UploadedFile ? $file->getSize() : 0, $files)) > config('feitoo.uploads.max_request_size_kb') * 1024) {
                $validator->errors()->add('files', 'Este envio excede o limite técnico de tamanho por requisição.');
            }
        }];
    }

    public function authorize(): bool
    {
        abort_if($this->route('processing')?->tool === ProcessingTool::QuotationGenerator, 409);

        // Ownership is checked by the route middleware before content validation.
        return true;
    }

    public function rules(): array
    {
        if ($this->route('processing')?->tool === ProcessingTool::DocxToPdf) {
            // Content is checked per file in ProcessingService so invalid DOCX does not
            // discard the valid neighbours. Unsafe documents are never stored or converted.
            return [
                'files' => ['required', 'array', 'min:1', 'max:'.min(config('documents.max_files'), config('feitoo.uploads.max_files'))],
                'files.*' => ['bail', 'required', 'file', 'extensions:docx', 'max:'.config('documents.max_file_size_kb')],
            ];
        }

        return [
            'files' => ['required', 'array', 'min:1', 'max:'.config('feitoo.uploads.max_files')],
            'files.*' => ['bail', 'required', 'file', 'extensions:xml', 'max:'.config('feitoo.uploads.max_file_size_kb'),
                ...($this->route('processing')?->tool === ProcessingTool::XmlAnalyzer ? [] : [$this->route('processing')?->tool === ProcessingTool::XmlOrganizer ? new SafeOrganizerXml : new SafeXml])],
        ];
    }
}
