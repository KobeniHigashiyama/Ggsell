<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Suppliers\HttpSupplierClient;
use App\Domain\Delivery\Suppliers\SupplierClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupplierClient::class, HttpSupplierClient::class);
    }

    public function boot(): void
    {
        // Lazy loading hides N+1 queries until production. Fail in development
        // and tests where the issue is inexpensive to fix.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Silently discarded mass-assignment attributes are silent data loss.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
