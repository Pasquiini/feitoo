<?php

namespace App\Http\Requests;

use App\Enums\ProcessingTool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProcessingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tool' => ['required', Rule::enum(ProcessingTool::class)],
            'organizer' => ['exclude_unless:tool,xml-organizer', 'sometimes', 'array:criterion,detect_duplicates,duplicate_action,report,standardize_names'],
            'organizer.criterion' => ['sometimes', Rule::in(['year_month', 'supplier', 'issuer_document', 'year_month_supplier', 'model'])],
            'organizer.detect_duplicates' => ['sometimes', 'boolean'],
            'organizer.duplicate_action' => ['sometimes', Rule::in(['keep', 'separate'])],
            'organizer.report' => ['sometimes', 'boolean'],
            'organizer.standardize_names' => ['sometimes', 'boolean'],
        ];
    }
}
