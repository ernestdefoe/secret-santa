<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 1.0.0 hand-named two unique indexes, and Laravel never prefixes a name it
 * is given: on PostgreSQL and SQLite, where index names are shared by the
 * whole database, two forums in one database collided. These replace them
 * with names that carry the table prefix.
 *
 * - Assignments: Laravel's own generated name (55 characters + the prefix).
 * - Exclusions: Laravel's generated name would be 59 characters + the prefix,
 *   past MySQL's 64 with a long prefix, so this one is built the way Laravel
 *   builds names (the prefix first, when prefix_indexes is on) but shorter.
 *
 * The new index goes in before the old one comes out: MySQL needs an index
 * on exchange_id for its foreign key at every moment. Each step checks what
 * is there, so a fresh install (which just ran the old migrations) and an
 * upgraded one end the same.
 */

/**
 * Swap the index named $old on $table for one named $new over the same
 * columns: add first, then drop, each only if needed.
 *
 * @param list<string> $columns
 */
$swap = function (Builder $schema, string $table, array $columns, string $old, ?string $new): void {
    // Null: Laravel's generated name for these columns, prefix included.
    $new ??= (fn () => $this->createIndexName('unique', $columns))->call(new Blueprint($schema->getConnection(), $table));
    if ($new === $old) {
        return;
    }
    if (! $schema->hasIndex($table, $new)) {
        $schema->table($table, fn (Blueprint $t) => $t->unique($columns, $new));
    }
    if ($schema->hasIndex($table, $old)) {
        $schema->table($table, fn (Blueprint $t) => $t->dropUnique($old));
    }
};

return [
    'up' => function (Builder $schema) use ($swap) {
        $connection = $schema->getConnection();
        $prefix = $connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() : '';

        $swap($schema, 'secret_santa_assignments', ['exchange_id', 'receiver_id'], 'secret_santa_assignments_receiver_unique', null);
        $swap($schema, 'secret_santa_exclusions', ['exchange_id', 'user_id', 'other_id'], 'secret_santa_exclusions_pair_unique', $prefix.'secret_santa_exclusions_pair_unique');
    },
    'down' => function (Builder $schema) {
        // The 1.0.0 names collide between forums sharing a database: there is nothing to go back to.
    },
];
