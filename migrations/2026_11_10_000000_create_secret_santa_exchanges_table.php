<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * An exchange: open for sign-ups until signup_ends_at, then drawn (matches
 * exist, nobody sees them), then active (each giver has been told who they
 * buy for), then complete. The counters are kept here so the list page is
 * one query.
 */
return Migration::createTable('secret_santa_exchanges', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('title', 100);
    $table->text('description')->nullable();
    $table->string('budget', 40)->nullable();
    $table->string('kind', 10)->default('physical');
    $table->string('status', 10)->default('open')->index();
    $table->dateTime('signup_ends_at')->index();
    $table->date('ship_by')->nullable();
    $table->boolean('hold_reveal')->default(false);
    $table->boolean('unmask')->default(true);
    $table->string('draw_error', 20)->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('participants_count')->default(0);
    $table->unsignedInteger('shipped_count')->default(0);
    $table->unsignedInteger('received_count')->default(0);
    $table->dateTime('drawn_at')->nullable();
    $table->dateTime('revealed_at')->nullable();
    $table->dateTime('completed_at')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
});
