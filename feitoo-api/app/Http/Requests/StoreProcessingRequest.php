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
        return ['tool' => ['required', Rule::enum(ProcessingTool::class)]];
    }
}
