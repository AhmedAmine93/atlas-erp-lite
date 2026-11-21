<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['customers', 'suppliers', 'products', 'quotes', 'orders', 'invoices', 'stock_movements', 'audit_logs'];

    public function up(): void
    {
        // Dedicated compatibility tenant for pre-existing mono-company records.
        $legacy = DB::table('companies')->where('code', 'ATLAS_DEMO')->value('id');
        if (! $legacy) {
            $legacy = DB::table('companies')->insertGetId([
                'code' => 'ATLAS_DEMO', 'name' => 'Atelier & Co. (démonstration)',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['company_id', 'user_id']);
        });

        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('company_user')->insertOrIgnore(['company_id' => $legacy, 'user_id' => $userId]);
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $schema) {
                $schema->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            });
            DB::table($table)->whereNull('company_id')->update(['company_id' => $legacy]);
            Schema::table($table, function (Blueprint $schema) {
                $schema->foreignId('company_id')->nullable(false)->change();
                $schema->index('company_id');
            });
        }

        // Identifiers are unique inside a tenant, not globally.
        foreach (['customers' => 'email', 'suppliers' => 'email', 'products' => 'reference'] as $table => $column) {
            Schema::table($table, function (Blueprint $schema) use ($table, $column) {
                $schema->dropUnique($table.'_'.$column.'_unique');
                $schema->unique(['company_id', $column]);
            });
        }
        foreach (['quotes', 'orders', 'invoices'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->index(['company_id', 'created_at']));
        }
    }

    public function down(): void
    {
        foreach (['quotes', 'orders', 'invoices'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropIndex(['company_id', 'created_at']));
        }
        foreach (['customers' => 'email', 'suppliers' => 'email', 'products' => 'reference'] as $table => $column) {
            Schema::table($table, function (Blueprint $schema) use ($table, $column) {
                $schema->dropUnique(['company_id', $column]);
                $schema->unique($column);
            });
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $schema) {
                $schema->dropIndex(['company_id']);
                $schema->dropConstrainedForeignId('company_id');
            });
        }
        Schema::dropIfExists('company_user');
    }
};
