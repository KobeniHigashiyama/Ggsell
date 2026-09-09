<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Payments\DTO\PaymentWebhookData;
use App\Support\Log\PaymentLog;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payment provider webhook contract.
 *
 * Signature verification is explicitly excluded by the assignment. Production
 * code would verify an HMAC before touching the database.
 */
class PaymentWebhookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:64'],
            'order_id' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', 'in:paid,failed'],
            // Use numeric because providers commonly serialize amounts as
            // "500.00". Limit decimals because unrestricted numeric accepts
            // values such as "1e20" that cannot become a meaningful minor-unit integer.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            // Use an allowlist because an unsupported currency indicates payment
            // misrouting, not an amount mismatch that order comparison can resolve.
            'currency' => ['required', 'string', 'size:3', Rule::in(config('ggsell.currencies'))],
            // Bounded on purpose. This timestamp becomes the business time of the
            // payment in the event log and drives the out-of-order guard, and the
            // endpoint is public and unsigned, so an unbounded value lets a caller
            // place a payment anywhere on the timeline: before its own order, or
            // far enough ahead that period reports and point-in-time answers
            // disagree with the ledger.
            'created_at' => ['nullable', 'date', 'before_or_equal:now', 'after:-1 year'],
        ];
    }

    /**
     * A rejected webhook may represent money that was actually received.
     *
     * The provider retries only 5xx responses, so a 422 is final. Log enough
     * information to support manual payment investigation.
     */
    protected function failedValidation(Validator $validator): void
    {
        // Log parsed fields instead of the full body. This endpoint is public,
        // and dumping every invalid request could flood the payment log. A body
        // hash is enough to correlate retries with load-balancer logs.
        PaymentLog::error('payment_event.rejected_by_validation', [
            'errors' => $validator->errors()->toArray(),
            'event_id' => $this->input('event_id'),
            'order_id' => $this->input('order_id'),
            'status' => $this->input('status'),
            'amount' => $this->input('amount'),
            'currency' => $this->input('currency'),
            'body_sha256' => hash('sha256', $this->getContent()),
        ]);

        parent::failedValidation($validator);
    }

    public function toData(): PaymentWebhookData
    {
        return new PaymentWebhookData(
            eventId: $this->string('event_id')->toString(),
            orderPublicId: $this->string('order_id')->toString(),
            status: $this->string('status')->toString(),
            // The provider sends major units while the system stores minor units.
            // Round instead of casting: 1290.35 may be slightly lower as a double,
            // and (int) ($value * 100) could produce 129034 instead of 129035.
            amountMinor: Money::fromMajor($this->input('amount')),
            currency: $this->string('currency')->upper()->toString(),
            // Normalized to the application timezone. Carbon keeps the offset the
            // provider sent, and Eloquent would then write that wall-clock time
            // into a UTC column: a webhook from +05:00 would be stored five hours
            // in the future, which is enough to make a newer event look stale and
            // be rejected.
            occurredAt: $this->filled('created_at')
                ? CarbonImmutable::parse($this->string('created_at')->toString())
                    ->setTimezone(config('app.timezone', 'UTC'))
                : null,
            raw: $this->all(),
        );
    }
}
