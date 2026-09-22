<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the users table the `NOT NULL` columns with no default that #36 is about.
 *
 * `tenant_id`, `organization_id`, `role_id`, a `first_name`/`last_name` pair -- the ticket lists
 * these as near-universal in a real application, and every one of them failed the very first GitHub
 * sign-in with a raw integrity error because `HostUsers::create()` wrote `name` and `email` and
 * nothing else.
 *
 * Two columns rather than one, so a test can tell "the host's attributes were used" from "one
 * lucky default covered it".
 */
return new class extends Migration
{
    /**
     * Add the columns.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'tenant_id')) {
                // No default on purpose: a default would make the insert succeed and the test
                // would pass whether or not the extension point was consulted.
                $table->unsignedBigInteger('tenant_id');
            }

            if (! Schema::hasColumn('users', 'external_ref')) {
                $table->string('external_ref', 128);
            }
        });
    }

    /**
     * Drop them.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['tenant_id', 'external_ref']);
        });
    }
};
