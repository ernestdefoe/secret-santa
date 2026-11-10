<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Two members who must not draw each other (a couple, roommates), either
 * way round. Stored with user_id < other_id so a pair is one row.
 */
return Migration::createTable('secret_santa_exclusions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('exchange_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('other_id');
    $table->unsignedInteger('created_by')->nullable();

    $table->unique(['exchange_id', 'user_id', 'other_id']);
    $table->foreign('exchange_id')->references('id')->on('secret_santa_exchanges')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('other_id')->references('id')->on('users')->cascadeOnDelete();
});
