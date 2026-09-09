<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\ViolationKind;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a code the supplier just handed over may be delivered.
 *
 * The database already guarantees that one code cannot reach two items, through
 * UNIQUE(deliveries.code). This check exists for the two things that guarantee
 * cannot do: name the incident, and catch it before a delivery attempt turns
 * into a constraint violation that has to be unpicked.
 *
 * It is deliberately not a trust decision about the supplier as a whole. Every
 * code is checked, every time, no matter how well behaved the source has been.
 */
final readonly class ValidateSupplierCode
{
    /**
     * @param  string|null  $reportedSku  What the supplier says the code is for; null when it did not say.
     * @return ViolationKind|null Null when the code may be delivered.
     */
    public function handle(OrderItem $item, string $code, ?string $reportedSku): ?ViolationKind
    {
        // An empty string is the same as silence: the supplier named no product.
        if ($reportedSku === null || $reportedSku === '') {
            // An unprovable code is refused rather than delivered on faith: the
            // customer would be the one discovering it is for the wrong product.
            return config('ggsell.suppliers.require_code_provenance')
                ? ViolationKind::UnverifiedCode
                : null;
        }

        if ($reportedSku !== $item->sku) {
            return ViolationKind::ForeignCode;
        }

        // Cheap pre-check for a code we have already seen. The unique index is
        // still the authority; this only lets the incident be named and retried
        // instead of surfacing as a rejected insert.
        if ($this->alreadyKnown($code)) {
            return ViolationKind::DuplicateCode;
        }

        return null;
    }

    private function alreadyKnown(string $code): bool
    {
        // Resolved orphans count too. A code that was handed back to its supplier
        // is revoked, not returned to circulation, so being offered it again is
        // itself the incident: accepting it would deliver something dead.
        return DB::table('deliveries')->where('code', $code)->exists()
            || DB::table('orphaned_codes')->where('code', $code)->exists();
    }
}
