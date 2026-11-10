<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member in an exchange. The wishlist and the shipping note are read only
 * by the member and, once matches are out, by whoever drew them
 * (Presenter::exchange): no other code path returns them.
 */
return Migration::createTable('secret_santa_participants', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('exchange_id');
    $table->unsignedInteger('user_id');
    $table->text('wishlist');
    $table->text('shipping_note')->nullable();
    $table->dateTime('joined_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->unique(['exchange_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('exchange_id')->references('id')->on('secret_santa_exchanges')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
