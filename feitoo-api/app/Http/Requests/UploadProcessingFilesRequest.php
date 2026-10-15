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
        // Ownership is checked by the route middleware before content validation.
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.config('feitoo.uploads.max_files')],
            'files.*' => ['bail', 'required', 'file', 'extensions:xml', 'max:'.config('feitoo.uploads.max_file_size_kb'),
                ...($this->route('processing')?->tool === ProcessingTool::XmlAnalyzer ? [] : [$this->route('processing')?->tool === ProcessingTool::XmlOrganizer ? new SafeOrganizerXml : new SafeXml])],
        ];
    }
}
