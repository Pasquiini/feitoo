<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

final class QuotationValidator
{
    public function validate(array $input): array
    {
        $allowed = ['schema_version', 'number', 'issue_date', 'valid_until', 'currency', 'issuer', 'customer', 'items', 'discount', 'conditions', 'notes', 'template_id'];
        try {
            $encoded = json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new QuotationValidationException(['document' => ['Codificação inválida.']]);
        }
        if (strlen($encoded) > QuotationLimits::MAX_PAYLOAD_BYTES || array_diff(array_keys($input), $allowed)) {
            throw new QuotationValidationException(['document' => ['Payload excedido ou campos desconhecidos.']]);
        }
        $text = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                $fail('Use texto UTF-8 sem caracteres de controle.');
            }
        };
        $integer = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('Use um inteiro em centavos.');
            }
        };
        $decimal = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,4})?\z/', $value)
                || BigDecimal::of($value)->isLessThanOrEqualTo(0) || BigDecimal::of($value)->isGreaterThan(QuotationLimits::MAX_QUANTITY)) {
                $fail('Quantidade deve ser decimal canônica positiva de até quatro casas e dentro do limite.');
            }
        };
        $rules = [
            'schema_version' => ['required', $integer, Rule::in([1])],
            'number' => ['required', 'string', 'max:60', 'not_regex:/[\r\n\t]/', $text],
            'issue_date' => ['required', 'string', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(['BRL'])],
            'template_id' => ['required', Rule::enum(QuotationTemplate::class)],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.QuotationLimits::MAX_ITEMS],
            'items.*' => ['required', 'array:description,quantity,unit_price_cents'],
            'items.*.description' => ['required', 'string', 'max:'.QuotationLimits::MAX_DESCRIPTION, $text],
            'items.*.quantity' => ['bail', 'required', $decimal],
            'items.*.unit_price_cents' => ['bail', 'required', $integer, 'integer', 'min:0', 'max:'.QuotationLimits::MAX_UNIT_PRICE_CENTS],
            'discount' => ['required', 'array:type,value'],
            'discount.type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount.value' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($input): void {
                if (($input['discount']['type'] ?? null) === 'percent') {
                    if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?\z/', $value) || BigDecimal::of($value)->isGreaterThan(100)) {
                        $fail('Percentual deve ser decimal canônico entre zero e cem.');
                    }
                } elseif (! is_int($value) || $value < 0 || $value > QuotationLimits::MAX_TOTAL_CENTS) {
                    $fail('Desconto fixo deve ser inteiro em centavos dentro do limite.');
                }
            }],
            'conditions' => ['present', 'string', 'max:'.QuotationLimits::MAX_NOTES, $text],
            'notes' => ['present', 'string', 'max:'.QuotationLimits::MAX_NOTES, $text],
        ];
        foreach (['issuer', 'customer'] as $party) {
            $rules[$party] = ['required', 'array:name,document,email,phone,address'];
            $rules[$party.'.name'] = ['required', 'string', 'max:160', 'not_regex:/[\r\n\t]/', $text];
            foreach (['document' => 30, 'email' => 254, 'phone' => 40, 'address' => 400] as $field => $max) {
                $rules[$party.'.'.$field] = ['sometimes', 'string', 'max:'.$max, 'not_regex:/[\r\n\t]/', $text];
            }
            if (($input[$party]['email'] ?? '') !== '') {
                $rules[$party.'.email'][] = 'email:rfc';
            }
        }
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            // No input values or arbitrary user-controlled keys in exception messages.
            $errors = [];
            foreach (array_keys($validator->failed()) as $field) {
                if (preg_match('/\A(?:schema_version|number|issue_date|valid_until|currency|template_id|conditions|notes|discount(?:\.(?:type|value))?|(?:issuer|customer)(?:\.(?:name|document|email|phone|address))?|items(?:\.[0-9]+(?:\.(?:description|quantity|unit_price_cents))?)?)\z/', $field)) {
                    $errors[$field] = ['Campo obrigatório, tipo ou limite inválido.'];
                }
            }
            throw new QuotationValidationException($errors ?: ['document' => ['Campos inválidos.']]);
        }

        return $validator->validated();
    }
}
