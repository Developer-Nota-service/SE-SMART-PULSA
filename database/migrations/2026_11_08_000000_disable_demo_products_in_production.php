<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Simulated catalog entries from older preview deployments must never
     * remain purchasable after the application is deployed to production.
     * Keep the rows for historical transaction foreign keys and auditability.
     */
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        DB::table('products')
            ->where('is_demo', true)
            ->update(['active' => false]);
    }

    /**
     * Intentionally do not reactivate simulated products on rollback.
     */
    public function down(): void
    {
        // No-op: re-enabling simulated products in production is unsafe.
    }
};
