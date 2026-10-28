<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `audit_logs.action` was varchar(16): the action `closed_inactivity` (17 characters, the automatic close of a support session) did not fit and PostgreSQL refused the insert
 * (SQLSTATE 22001). Widened to 32 so the names of the actions of today and of tomorrow fit; nothing is truncated or renamed and no row changes. Reversible while every stored
 * action still fits in 16 characters (after the first `closed_inactivity` row, rolling back needs those rows to be handled first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 16)->change();
        });
    }
};
