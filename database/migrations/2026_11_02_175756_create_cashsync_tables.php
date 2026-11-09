<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CashSync tables. Each bank server (A and B) has its OWN copy of these tables
 * in its own database (cashsync_a / cashsync_b).
 * Every table uses a UUID as its id, so A and B can create records without ever
 * producing the same id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Bank accounts ----------
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();                 // e.g. 9d3c1f6e-8a2b-4c7d-...
            $table->string('account_no', 20)->unique();    // what the customer types at the ATM, e.g. 1001
            $table->string('holder_name');
            $table->string('pin_hash');                    // the PIN is stored hashed, never as plain text
            $table->bigInteger('balance')->default(0);     // whole Uganda shillings
            // Goes up by 1 every time the balance changes. The backup uses it to accept only
            // NEWER copies of an account, never an older one.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();                          // created_at and updated_at
        });

        // ---------- Every request the bank processed (approved or rejected) ----------
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The UUID the ATM made for this customer action. UNIQUE: the same request can
            // never be stored twice, which is what makes retries safe.
            $table->uuid('request_id')->unique();
            $table->string('atm_id', 20)->nullable();      // which machine sent it: ATM1, ATM2 ...
            $table->string('type', 20);                    // withdraw, deposit, transfer, adjust
            $table->string('account_no', 20);
            $table->string('to_account_no', 20)->nullable(); // only for transfers
            $table->bigInteger('amount')->default(0);
            $table->string('status', 20);                  // approved or rejected
            $table->string('message')->nullable();         // e.g. "Insufficient funds."
            $table->bigInteger('balance_after')->nullable();
            $table->bigInteger('to_balance_after')->nullable();
            $table->unsignedInteger('epoch')->default(1);  // which "reign" of primary processed it
            $table->string('processed_by', 5)->nullable(); // server A or B
            $table->text('response');                      // the exact reply sent; replayed on retries
            $table->timestamps();
            // Speeds up the daily-limit check (today's withdrawals for one account)
            $table->index(['account_no', 'type', 'status', 'created_at']);
        });

        // ---------- One row describing THIS server ----------
        Schema::create('node_state', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role', 10);                    // primary or backup
            $table->unsignedInteger('epoch')->default(1);  // goes up every time a new primary takes over
            $table->string('peer_status', 10)->default('unknown'); // the other server: up, down, unknown
            $table->string('peer_role', 10)->nullable();
            $table->unsignedInteger('peer_epoch')->nullable();
            $table->timestamp('peer_seen_at')->nullable(); // last heartbeat answer from the other server
            $table->boolean('degraded')->default(false);   // primary working WITHOUT a backup confirming copies
            $table->timestamps();
        });

        // ---------- A short history of important events (shown on the dashboard) ----------
        Schema::create('node_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level', 10)->default('info');  // info, warning, danger, success
            $table->string('message');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_events');
        Schema::dropIfExists('node_state');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');
    }
};