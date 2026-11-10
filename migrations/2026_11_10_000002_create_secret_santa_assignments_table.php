<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Who buys for whom. The draw makes a single cycle, one row per member; a
 * rescue Santa (Dropouts::rescue) may hold a second row. Never more than one
 * Santa per receiver. A giver reads their own row; a receiver reads the delivery
 * half of theirs (shipped, the gift note, received) but never giver_id,
 * unless the exchange unmasks Santas when it ends.
 */
return Migration::createTable('secret_santa_assignments', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('exchange_id');
    $table->unsignedInteger('giver_id');
    $table->unsignedInteger('receiver_id');
    $table->dateTime('shipped_at')->nullable();
    $table->text('gift_note')->nullable();
    $table->dateTime('received_at')->nullable();
    $table->text('thanks')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->unique(['exchange_id', 'receiver_id']);
    $table->index('giver_id');
    $table->index('receiver_id');
    $table->foreign('exchange_id')->references('id')->on('secret_santa_exchanges')->cascadeOnDelete();
    $table->foreign('giver_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('receiver_id')->references('id')->on('users')->cascadeOnDelete();
});
