<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The organizer reveal is the only way anyone but the two ends sees a match,
 * and every use is written down: who looked, at whose match, and when. The
 * exchange's organizers see this log.
 */
return Migration::createTable('secret_santa_reveals', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('exchange_id');
    $table->unsignedInteger('actor_id')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->string('reason', 200)->nullable();
    $table->dateTime('created_at')->nullable();

    $table->index('exchange_id');
    $table->foreign('exchange_id')->references('id')->on('secret_santa_exchanges')->cascadeOnDelete();
    $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
