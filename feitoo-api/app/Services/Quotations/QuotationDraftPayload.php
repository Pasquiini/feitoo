<?php

namespace App\Services\Quotations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Editing schema: bounded incomplete values are preserved; issuance uses Quotation::fromArray. */
class QuotationDraftPayload
{
    public static function validate(mixed $payload): array
    {
        $fields = ['number' => 60, 'issue_date' => 10, 'valid_until' => 10,
            'discount_percent' => 24, 'conditions' => 4000, 'notes' => 4000];
        $keys = [...array_keys($fields), 'issuer', 'customer', 'items', 'discount_type', 'discount_fixed_cents', 'template_id', 'money_inputs'];
        $rules = ['payload' => ['required', 'array:'.implode(',', $keys)],
            'payload.items' => ['present', 'array', 'list', 'max:100'],
            'payload.items.*' => ['array:description,quantity,unit_price_cents'],
            'payload.items.*.description' => ['present', 'string', 'max:1000'],
            'payload.items.*.quantity' => ['present', 'string', 'max:24'],
            'payload.items.*.unit_price_cents' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'payload.discount_type' => ['required', 'in:fixed,percent'],
            'payload.discount_fixed_cents' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'payload.template_id' => ['required', 'in:classic,modern'],
            'payload.money_inputs' => ['sometimes', 'array:prices,fixed_discount'],
            'payload.money_inputs.prices' => ['required_with:payload.money_inputs', 'array', 'list', 'max:100'],
            'payload.money_inputs.prices.*' => ['string', 'max:64'],
            'payload.money_inputs.fixed_discount' => ['present_with:payload.money_inputs', 'string', 'max:64']];
        foreach ($fields as $field => $max) {
            $rules['payload.'.$field] = ['present', 'string', 'max:'.$max];
        }
        foreach (['issuer', 'customer'] as $party) {
            $rules['payload.'.$party] = ['required', 'array:name,document,email,phone,address'];
            foreach (['name' => 160, 'document' => 30, 'email' => 254, 'phone' => 40, 'address' => 400] as $field => $max) {
                $rules['payload.'.$party.'.'.$field] = ['present', 'string', 'max:'.$max];
            }
        }
        // Do not reflect unknown field names or values into errors/logs.
        $validator = Validator::make(['payload' => $payload], $rules);
        if ($validator->fails() || strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) > 262144) {
            throw ValidationException::withMessages(['payload' => ['Rascunho inválido ou acima dos limites permitidos.']]);
        }
        $walk = function (mixed $value, string $key = '') use (&$walk): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, (string) $k);
                }
            } elseif (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value))) {
                throw ValidationException::withMessages(['payload' => ['Texto inválido.']]);
            } elseif (in_array($key, ['unit_price_cents', 'discount_fixed_cents'], true) && $value !== null && ! is_int($value)) {
                throw ValidationException::withMessages(['payload' => ['Valores em centavos devem ser inteiros.']]);
            }
        };
        $walk($payload);

        return $payload;
    }

    public static function fingerprint(array $payload): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $value);
        };

        return hash_hmac('sha256', json_encode($sort($payload), JSON_UNESCAPED_UNICODE), config('app.key'));
    }
}
