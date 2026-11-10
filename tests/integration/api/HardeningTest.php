<?php

namespace ErnestDefoe\SecretSanta\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Dropouts;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Job\SendNews;
use ErnestDefoe\SecretSanta\Tests\integration\SantaTestCase;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\NullQueue;
use PHPUnit\Framework\Attributes\Test;

/** The inference holes and races found in review, one test each. */
class HardeningTest extends SantaTestCase
{
    private function draw(): array
    {
        $this->call('GET', '/api/secret-santa', 7);

        return $this->pairs();
    }

    #[Test]
    public function no_row_id_reaches_the_browser()
    {
        $pairs = $this->draw();
        $this->call('POST', '/api/secret-santa/1/message', 3, ['giftee' => $pairs[3], 'body' => 'hello']);

        foreach ([3, $pairs[3]] as $who) {
            [, $json] = $this->call('GET', '/api/secret-santa/1', $who);
            foreach ($json['data']['giving'] as $row) {
                $this->assertArrayNotHasKey('id', $row);
                foreach ($row['messages'] as $m) {
                    $this->assertArrayNotHasKey('id', $m);
                }
            }
            $this->assertArrayNotHasKey('id', $json['data']['receiving']);
            foreach ($json['data']['receiving']['messages'] as $m) {
                $this->assertArrayNotHasKey('id', $m);
            }
        }
    }

    #[Test]
    public function rows_are_not_stored_in_cycle_order()
    {
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['hold_reveal' => 1]);
        $followed = 0;
        for ($i = 0; $i < 20; $i++) {
            $this->call('POST', '/api/secret-santa/1/draw', 2);
            $rows = $this->database()->table('secret_santa_assignments')->orderBy('id')->get(['giver_id', 'receiver_id'])->all();
            $chain = true;
            for ($k = 1; $k < count($rows); $k++) {
                $chain = $chain && (int) $rows[$k]->giver_id === (int) $rows[$k - 1]->receiver_id;
            }
            $followed += (int) $chain;
        }
        // In cycle order every time before; a shuffle follows the cycle about 1 time in 24.
        $this->assertLessThan(6, $followed);
    }

    #[Test]
    public function matches_and_endings_are_sent_on_the_queue()
    {
        $this->app()->getContainer()->instance(Queue::class, new NullQueue());
        $this->draw();
        $this->assertCount(5, $this->pairs());
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'secretSantaMatched')->count(), 'nothing sent inside the request');

        $this->app()->getContainer()->call([new SendNews(1, 'matched'), 'handle']);
        $this->assertSame(5, $this->database()->table('notifications')->where('type', 'secretSantaMatched')->count());
        // Run twice (a retry): nothing new.
        $this->app()->getContainer()->call([new SendNews(1, 'matched'), 'handle']);
        $this->assertSame(5, $this->database()->table('notifications')->where('type', 'secretSantaMatched')->count());
    }

    #[Test]
    public function the_send_by_date_cannot_be_moved_earlier_to_show_who_has_sent()
    {
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['ship_by' => Carbon::now()->addDays(20)->toDateString()]);
        $this->draw();
        $exchange = $this->database()->table('secret_santa_exchanges')->where('id', 1)->first();
        $this->assertNotNull($exchange->sent_visible_at);

        [$status, $json] = $this->call('PATCH', '/api/secret-santa/1', 2, ['title' => 'Holiday 2026', 'shipBy' => Carbon::now()->subDay()->toDateString()]);
        $this->assertSame('secret_santa_ship_by_later', $json['errors'][0]['code']);

        // Even a date edited in the database doesn't bring the flags forward: fixed at the reveal.
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['ship_by' => Carbon::now()->subDay()->toDateString()]);
        [, $json] = $this->call('GET', '/api/secret-santa/1', 2);
        $this->assertNotNull($json['data']['organizing']['sentHiddenUntil']);

        // A later date is fine.
        [$status] = $this->call('PATCH', '/api/secret-santa/1', 2, ['title' => 'Holiday 2026', 'shipBy' => Carbon::now()->addDays(30)->toDateString()]);
        $this->assertSame(200, $status);
    }

    #[Test]
    public function every_organizer_waits_for_the_sent_flags()
    {
        $pairs = $this->draw();
        $this->call('POST', '/api/secret-santa/1/shipped', 3, ['giftee' => $pairs[3]]);

        // The admin isn't in the exchange, and still waits: flags could be lined up with what members say.
        [, $admin] = $this->call('GET', '/api/secret-santa/1', 1);
        $this->assertNotNull($admin['data']['organizing']['sentHiddenUntil']);
        $this->assertSame([], array_filter($admin['data']['organizing']['members'], fn ($row) => $row['shipped'] !== null));

        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['sent_visible_at' => Carbon::now()->subMinute()]);
        [, $admin] = $this->call('GET', '/api/secret-santa/1', 1);
        $sent = array_values(array_filter($admin['data']['organizing']['members'], fn ($row) => $row['shipped']));
        $this->assertSame([3], array_map(fn ($row) => $row['user']['id'], $sent));
    }

    #[Test]
    public function unmasking_is_settled_once_sign_ups_close()
    {
        $this->draw();
        $this->call('PATCH', '/api/secret-santa/1', 2, ['title' => 'Holiday 2026', 'unmask' => false]);
        $this->assertSame(1, (int) $this->database()->table('secret_santa_exchanges')->where('id', 1)->value('unmask'));
    }

    #[Test]
    public function a_stale_open_status_cannot_wipe_a_live_exchanges_matches()
    {
        // Read while still open...
        $this->app();
        $stale = Exchange::query()->find(1);
        // ...then the draw runs and matches go out.
        $pairs = $this->draw();
        $this->assertSame('active', $this->database()->table('secret_santa_exchanges')->where('id', 1)->value('status'));

        $santaOf6 = $this->giverOf(6);
        $this->app()->getContainer()->make(Dropouts::class)->remove($stale, 6);

        // Handled as the live exchange it is: every other match is still there.
        foreach ($pairs as $giver => $receiver) {
            if (! in_array(6, [$giver, $receiver], true) && $giver !== $santaOf6) {
                $this->assertTrue($this->database()->table('secret_santa_assignments')->where('giver_id', $giver)->where('receiver_id', $receiver)->exists(), "$giver > $receiver kept");
            }
        }

        // Leaving and excluding re-read the status too.
        [$status] = $this->call('DELETE', '/api/secret-santa/1/entry', 3);
        $this->assertSame(422, $status);
        [$status] = $this->call('POST', '/api/secret-santa/1/exclude', 2, ['a' => 3, 'b' => 4]);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function index_and_key_names_fit_mysql_behind_a_long_prefix()
    {
        $db = $this->database();
        $prefix = $db->getTablePrefix();
        $names = match ($db->getDriverName()) {
            'sqlite' => array_column($db->select("select name from sqlite_master where type = 'index' and tbl_name like ?", [$prefix.'secret_santa%']), 'name'),
            'pgsql' => array_column($db->select('select indexname as name from pg_indexes where tablename like ? union select constraint_name from information_schema.table_constraints where table_name like ?', [$prefix.'secret_santa%', $prefix.'secret_santa%']), 'name'),
            default => array_column($db->select('select constraint_name as name from information_schema.table_constraints where table_schema = database() and table_name like ? union select index_name from information_schema.statistics where table_schema = database() and table_name like ?', [$prefix.'secret_santa%', $prefix.'secret_santa%']), 'name'),
        };
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            if (str_starts_with($name, 'sqlite_autoindex') || $name === 'PRIMARY' || str_ends_with($name, '_pkey') || str_ends_with($name, '_not_null')) {
                continue;
            }
            $bare = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            $this->assertLessThanOrEqual(64, strlen($bare) + 9, "$bare is too long behind a 9-character prefix");
        }
    }
}
