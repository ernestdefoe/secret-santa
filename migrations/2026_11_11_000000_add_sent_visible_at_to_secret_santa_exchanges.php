<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * When organizers may see who has sent their gift, fixed the moment matches
 * go out (Draw::reveal), so editing the send-by date later can't bring it
 * forward. Exchanges already under way get theirs from what they have.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('secret_santa_exchanges', function (Blueprint $table) {
            $table->dateTime('sent_visible_at')->nullable()->after('revealed_at');
        });

        $db = $schema->getConnection();
        foreach ($db->table('secret_santa_exchanges')->whereNotNull('revealed_at')->get(['id', 'ship_by', 'revealed_at']) as $row) {
            $db->table('secret_santa_exchanges')->where('id', $row->id)->update([
                'sent_visible_at' => \ErnestDefoe\SecretSanta\Draw::sentVisibleAt(
                    $row->ship_by ? Carbon::parse($row->ship_by) : null,
                    Carbon::parse($row->revealed_at)
                ),
            ]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('secret_santa_exchanges', function (Blueprint $table) {
            $table->dropColumn('sent_visible_at');
        });
    },
];
