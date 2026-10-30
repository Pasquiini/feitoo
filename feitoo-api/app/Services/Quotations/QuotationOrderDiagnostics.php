<?php

namespace App\Services\Quotations;

use App\Exceptions\BillingException;
use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationCreditOrder;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Throwable;

class QuotationOrderDiagnostics
{
    public static function stage(string $stage, ?QuotationCreditOrder $order = null): void
    {
        $context = request()->attributes->get('quotation_order_diagnostics', []);
        $context['stage'] = $stage;
        if ($order) {
            $context['order_id'] = $order->id;
            $context['stripe_mode'] = in_array($order->stripe_mode, ['test', 'live'], true) ? $order->stripe_mode : null;
            $context['return_destination'] = in_array($order->return_destination, ['account', 'editor'], true) ? $order->return_destination : null;
            $context['has_session'] = (bool) $order->session_id;
        }
        request()->attributes->set('quotation_order_diagnostics', $context);
    }

    public static function report(Throwable $error): void
    {
        // Explicit allowlist: never pass the exception, message, trace, request body or SQL bindings to the logger.
        $reported = request()->attributes->get('quotation_order_reported', []);
        if (in_array(spl_object_id($error), $reported, true)) {
            return;
        }
        $context = request()->attributes->get('quotation_order_diagnostics', ['stage' => 'request']);
        $context += ['exception_type' => $error::class, 'source_file' => basename($error->getFile()), 'source_line' => $error->getLine()];
        if ($error instanceof ApiErrorException) {
            $context['stripe_http_status'] = $error->getHttpStatus();
            $id = $error->getRequestId();
            $context['stripe_request_id'] = is_string($id) && preg_match('/\Areq_[a-zA-Z0-9]{1,128}\z/', $id) ? $id : null;
            $code = $error->getStripeCode();
            $context['stripe_error_code'] = in_array($code, ['parameter_unknown', 'parameter_invalid_empty', 'parameter_invalid_integer',
                'parameter_invalid_string_blank', 'parameter_missing', 'url_invalid', 'resource_missing', 'api_key_expired',
                'idempotency_key_in_use', 'lock_timeout', 'rate_limit'], true) ? $code : null;
            $param = $error instanceof InvalidRequestException ? $error->getStripeParam() : null;
            $context['stripe_parameter'] = in_array($param, ['success_url', 'cancel_url', 'expires_at', 'metadata', 'payment_intent_data',
                'line_items', 'line_items[0][price]', 'adaptive_pricing', 'adaptive_pricing[enabled]', 'automatic_tax',
                'allow_promotion_codes', 'payment_method_types', 'client_reference_id'], true) ? $param : null;
        } elseif ($error instanceof QuotationGenerationException || $error instanceof BillingException) {
            $context['reason'] = $error->reason;
        }
        Log::error('Quotation credit order failed.', $context);
        request()->attributes->set('quotation_order_reported', [...$reported, spl_object_id($error)]);
    }
}
