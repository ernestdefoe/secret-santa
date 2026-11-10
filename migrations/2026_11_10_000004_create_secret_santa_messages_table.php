<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Anonymous notes between a Santa and their giftee ("any allergies?"). The
 * row belongs to the assignment, so it can only ever be read by its two
 * ends, and the giftee's side never learns who wrote from the Santa's.
 */
return Migration::createTable('secret_santa_messages', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('assignment_id');
    $table->boolean('from_giver');
    $table->text('body');
    $table->dateTime('created_at')->nullable();

    $table->index('assignment_id');
    $table->foreign('assignment_id')->references('id')->on('secret_santa_assignments')->cascadeOnDelete();
});
