<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand: keep users.role_id for legacy compatibility, but authorization
        // will now use only the role on the active company membership.
        Schema::table('company_user', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
        });

        // Preserve each existing user's previous permissions on every company.
        // This works on SQLite and PostgreSQL without relying on model scopes.
        DB::statement('UPDATE company_user SET role_id = (SELECT users.role_id FROM users WHERE users.id = company_user.user_id)');

        if (DB::table('company_user')->whereNull('role_id')->exists()) {
            throw new \RuntimeException('Cannot migrate: a company membership has no role.');
        }

        Schema::table('company_user', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
            $table->index(['company_id', 'role_id'], 'company_user_company_role_idx');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropIndex('company_user_company_role_idx');
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
