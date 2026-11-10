<?php

namespace ErnestDefoe\SecretSanta\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SecretSanta\Tests\integration\SantaTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Starting, joining, drawing, sending out, shipping, dropouts and the end. */
class FlowTest extends SantaTestCase
{
    private function draw(): array
    {
        $this->call('GET', '/api/secret-santa', 7);

        return $this->pairs();
    }

    private function assertCycle(array $pairs, array $members): void
    {
        $this->assertEqualsCanonicalizing($members, array_keys($pairs));
        $this->assertEqualsCanonicalizing($members, array_values($pairs));
        $seen = [];
        $at = $members[0];
        do {
            $this->assertNotSame($at, $pairs[$at], 'nobody draws themselves');
            $seen[] = $at;
            $at = $pairs[$at];
        } while ($at !== $members[0]);
        $this->assertCount(count($members), $seen, 'one cycle through everyone');
    }

    #[Test]
    public function a_member_starts_an_exchange_and_others_join_with_a_wishlist_and_note()
    {
        [$status, $json] = $this->call('POST', '/api/secret-santa', 7, [
            'title' => 'Board game swap', 'budget' => '$30', 'kind' => 'physical',
            'signupEndsAt' => Carbon::now()->addDays(7)->toIso8601String(), 'shipBy' => Carbon::now()->addDays(20)->toDateString(),
            'description' => "Games only.\nUsed is fine.",
        ]);
        $this->assertSame(201, $status);
        $id = $json['data']['id'];
        $this->assertSame('open', $json['data']['status']);
        $this->assertTrue($json['data']['can']['organize']);

        // Physical exchanges need a shipping note; wishlists are always needed.
        [$status, $json] = $this->call('POST', "/api/secret-santa/$id/entry", 3, ['wishlist' => 'Catan']);
        $this->assertSame('secret_santa_bad_note', $json['errors'][0]['code']);
        [$status, $json] = $this->call('POST', "/api/secret-santa/$id/entry", 3, ['shippingNote' => '1 Main St, Springfield']);
        $this->assertSame('secret_santa_bad_wishlist', $json['errors'][0]['code']);

        [$status, $json] = $this->call('POST', "/api/secret-santa/$id/entry", 3, ['wishlist' => 'Catan', 'shippingNote' => '1 Main St, Springfield']);
        $this->assertSame(201, $status);
        $this->assertSame('Catan', $json['data']['me']['wishlist']);
        $this->assertSame(1, $json['data']['participantsCount']);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 7)->where('type', 'secretSantaJoined')->count());

        [$status] = $this->call('POST', "/api/secret-santa/$id/entry", 3, ['wishlist' => 'again', 'shippingNote' => 'again again']);
        $this->assertSame(422, $status);

        // Leaving while sign-ups are open.
        [$status, $json] = $this->call('DELETE', "/api/secret-santa/$id/entry", 3);
        $this->assertSame(200, $status);
        $this->assertNull($json['data']['me']);
        $this->assertSame(0, $json['data']['participantsCount']);
    }

    #[Test]
    public function bad_exchanges_are_refused()
    {
        foreach ([
            ['title' => 'x', 'signupEndsAt' => Carbon::now()->addDay()->toIso8601String()],
            ['title' => 'Past', 'signupEndsAt' => Carbon::now()->subDay()->toIso8601String()],
            ['title' => 'Far', 'signupEndsAt' => Carbon::now()->addYears(2)->toIso8601String()],
            ['title' => 'Kind', 'kind' => 'pony', 'signupEndsAt' => Carbon::now()->addDay()->toIso8601String()],
            ['title' => 'Ship', 'signupEndsAt' => Carbon::now()->addDays(5)->toIso8601String(), 'shipBy' => Carbon::now()->toDateString()],
        ] as $body) {
            [$status] = $this->call('POST', '/api/secret-santa', 7, $body);
            $this->assertSame(422, $status, json_encode($body));
        }

        [$status] = $this->call('POST', '/api/secret-santa', null, ['title' => 'Guest', 'signupEndsAt' => Carbon::now()->addDay()->toIso8601String()]);
        $this->assertContains($status, [400, 401], 'guests cannot start one');
    }

    #[Test]
    public function no_joining_after_the_deadline()
    {
        [$status, $json] = $this->call('POST', '/api/secret-santa/1/entry', 7, ['wishlist' => 'Socks', 'shippingNote' => 'Somewhere 1']);
        $this->assertSame(422, $status);
        $this->assertSame('secret_santa_closed', $json['errors'][0]['code']);
    }

    #[Test]
    public function the_deadline_draws_one_cycle_and_tells_every_santa()
    {
        $pairs = $this->draw();
        $this->assertCycle($pairs, self::MEMBERS);
        $this->assertSame('active', $this->database()->table('secret_santa_exchanges')->where('id', 1)->value('status'));

        foreach ($pairs as $giver => $receiver) {
            $row = $this->database()->table('notifications')->where('user_id', $giver)->where('type', 'secretSantaMatched')->first();
            $this->assertNotNull($row);
            $this->assertSame($receiver, (int) $row->subject_id);
        }

        // A second read draws nothing again.
        $this->call('GET', '/api/secret-santa/1', 3);
        $this->assertSame($pairs, $this->pairs());
    }

    #[Test]
    public function the_scheduled_command_draws_too()
    {
        $this->app();
        $this->app()->getContainer()->make(\ErnestDefoe\SecretSanta\Draw::class)->due();
        $this->assertCount(count(self::MEMBERS), $this->pairs());
    }

    #[Test]
    public function exclusions_are_honored()
    {
        // Member 3 and 4 are a couple; both ways round.
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['hold_reveal' => 1]);
        $this->database()->table('secret_santa_exclusions')->insert(['exchange_id' => 1, 'user_id' => 3, 'other_id' => 4, 'created_by' => 3]);

        for ($i = 0; $i < 15; $i++) {
            [$status] = $this->call('POST', '/api/secret-santa/1/draw', 2);
            $this->assertSame(200, $status);
            $pairs = $this->pairs();
            $this->assertCycle($pairs, self::MEMBERS);
            $this->assertNotSame(4, $pairs[3]);
            $this->assertNotSame(3, $pairs[4]);
        }
    }

    #[Test]
    public function nobody_may_be_left_with_fewer_than_three_possible_matches()
    {
        // 5 members: each has 4 others, so each may lose one to an exclusion, not two.
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['status' => 'open', 'signup_ends_at' => Carbon::now()->addDay()]);
        [$status] = $this->call('PATCH', '/api/secret-santa/1/entry', 3, ['wishlist' => 'WISH-3', 'shippingNote' => 'NOTE-3', 'exclusions' => [4]]);
        $this->assertSame(200, $status);

        // A second for member 3, by themselves or by the organizer: refused.
        [$status, $json] = $this->call('PATCH', '/api/secret-santa/1/entry', 3, ['wishlist' => 'WISH-3', 'shippingNote' => 'NOTE-3', 'exclusions' => [4, 5]]);
        $this->assertSame('secret_santa_exclusions_tight', $json['errors'][0]['code']);
        [$status, $json] = $this->call('POST', '/api/secret-santa/1/exclude', 2, ['a' => 3, 'b' => 6]);
        $this->assertSame('secret_santa_exclusions_tight', $json['errors'][0]['code']);
        // Nor through member 4, who is already kept from 3.
        [$status, $json] = $this->call('PATCH', '/api/secret-santa/1/entry', 4, ['wishlist' => 'WISH-4', 'shippingNote' => 'NOTE-4', 'exclusions' => [5]]);
        $this->assertSame('secret_santa_exclusions_tight', $json['errors'][0]['code']);
        $this->assertSame(1, $this->database()->table('secret_santa_exclusions')->count());

        // Pairs that corner someone anyway (the exchange shrank) stop the draw, and say so.
        $this->database()->table('secret_santa_exclusions')->insert(['exchange_id' => 1, 'user_id' => 3, 'other_id' => 5, 'created_by' => 2]);
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['signup_ends_at' => Carbon::now()->subMinute()]);
        $this->draw();
        $exchange = $this->database()->table('secret_santa_exchanges')->where('id', 1)->first();
        $this->assertSame(['open', 'exclusions'], [$exchange->status, $exchange->draw_error]);
        $this->assertSame([], $this->pairs());
    }

    #[Test]
    public function too_few_members_is_reported_to_the_organizer()
    {
        // Three is too few: A buys for B, so C must be A's Santa.
        $this->database()->table('secret_santa_participants')->whereIn('user_id', [5, 6])->delete();
        $this->draw();

        $exchange = $this->database()->table('secret_santa_exchanges')->where('id', 1)->first();
        $this->assertSame(['open', 'too_few'], [$exchange->status, $exchange->draw_error]);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 2)->where('type', 'secretSantaNews')->count());

        // Moving the deadline lets it try again later.
        [$status] = $this->call('PATCH', '/api/secret-santa/1', 2, ['title' => 'Holiday 2026', 'signupEndsAt' => Carbon::now()->addDays(3)->toIso8601String()]);
        $this->assertSame(200, $status);
        $this->assertNull($this->database()->table('secret_santa_exchanges')->where('id', 1)->value('draw_error'));
    }

    #[Test]
    public function only_organizers_run_the_exchange()
    {
        foreach (['draw', 'reveal', 'finish', 'dropout', 'exclude', 'thread'] as $action) {
            [$status] = $this->call('POST', "/api/secret-santa/1/$action", 3, ['user' => 4, 'a' => 4, 'b' => 5]);
            $this->assertSame(403, $status, $action);
        }
        [$status] = $this->call('PATCH', '/api/secret-santa/1', 3, ['title' => 'Mine now']);
        $this->assertSame(403, $status);
        [$status] = $this->call('DELETE', '/api/secret-santa/1', 3);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function the_organizer_reviews_redraws_then_sends_out()
    {
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['hold_reveal' => 1]);
        $this->draw();
        [, $json] = $this->call('GET', '/api/secret-santa/1', 2);
        $this->assertSame('drawn', $json['data']['status']);
        $this->assertSame(5, $json['data']['organizing']['matchCount']);

        [$status] = $this->call('POST', '/api/secret-santa/1/draw', 2);
        $this->assertSame(200, $status);
        [$status, $json] = $this->call('POST', '/api/secret-santa/1/reveal', 2);
        $this->assertSame('active', $json['data']['status']);
        $this->assertSame(5, $this->database()->table('notifications')->where('type', 'secretSantaMatched')->count());

        // No re-draw after matches are out.
        [$status] = $this->call('POST', '/api/secret-santa/1/draw', 2);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function shipping_receiving_and_notes_go_to_the_right_people()
    {
        $pairs = $this->draw();
        $giftee = $pairs[3];

        [$status, $json] = $this->call('POST', '/api/secret-santa/1/shipped', 3, ['giftee' => $giftee, 'note' => 'Tracking 1Z999']);
        $this->assertSame(200, $status);
        $this->assertSame(1, $json['data']['shippedCount']);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', $giftee)->where('type', 'secretSantaShipped')->count());

        [, $json] = $this->call('GET', '/api/secret-santa/1', $giftee);
        $this->assertSame('Tracking 1Z999', $json['data']['receiving']['giftNote']);

        $this->call('POST', '/api/secret-santa/1/message', 3, ['giftee' => $giftee, 'body' => 'Any allergies?']);
        $this->call('POST', '/api/secret-santa/1/message', $giftee, ['to' => 'santa', 'body' => 'Peanuts!']);
        [, $santaView] = $this->call('GET', '/api/secret-santa/1', 3);
        $this->assertSame([[true, 'Any allergies?'], [false, 'Peanuts!']], array_map(fn ($m) => [$m['mine'], $m['body']], $santaView['data']['giving'][0]['messages']));
        [, $gifteeView] = $this->call('GET', '/api/secret-santa/1', $giftee);
        $this->assertSame([[false, 'Any allergies?'], [true, 'Peanuts!']], array_map(fn ($m) => [$m['mine'], $m['body']], $gifteeView['data']['receiving']['messages']));
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'secretSantaMessage')->where('from_user_id', $giftee)->count());

        [$status, $json] = $this->call('POST', '/api/secret-santa/1/received', $giftee, ['thanks' => 'Love it!']);
        $this->assertSame(1, $json['data']['receivedCount']);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'secretSantaReceived')->count());
        [, $santaView] = $this->call('GET', '/api/secret-santa/1', 3);
        $this->assertSame('Love it!', $santaView['data']['giving'][0]['thanks']);

        // Once received, the Santa can't take "shipped" back.
        [$status] = $this->call('POST', '/api/secret-santa/1/shipped', 3, ['giftee' => $giftee, 'undo' => true]);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function a_dropout_gives_nobody_a_pair_to_work_out()
    {
        $pairs = $this->draw();
        $dropout = 4;
        $santa = $this->giverOf($dropout);
        $giftee = $pairs[$dropout];

        [$status, $json] = $this->call('POST', '/api/secret-santa/1/dropout', 2, ['user' => $dropout]);
        $this->assertSame(200, $status);

        // Their Santa is let off: never handed the dropout's giftee (that would name the dropout's pair).
        $after = $this->pairs();
        $this->assertArrayNotHasKey($santa, $after);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', $santa)->where('type', 'secretSantaNews')->where('is_deleted', 0)->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', $santa)->where('type', 'secretSantaMatched')->where('is_deleted', 0)->count());

        // The giftee gets someone else, at random, as a rescue.
        $rescuer = $this->giverOf($giftee);
        $this->assertNotContains($rescuer, [0, $santa, $dropout, $giftee]);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', $rescuer)->where('subject_id', $giftee)->where('type', 'secretSantaMatched')->where('is_deleted', 0)->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', $dropout)->where('type', 'secretSantaMatched')->where('is_deleted', 0)->count());

        // The organizer sees nobody waiting, so their view doesn't name the pair either.
        $this->assertSame([], array_filter($json['data']['organizing']['members'], fn ($row) => $row['waiting']));
    }

    /** A fixed cycle 2>3>4>5>6>8>2 (8 joins), drawn and sent out, so a test knows every pair. */
    private function fixedCycle(): void
    {
        $this->database()->table('users')->insertOrIgnore(['id' => 8, 'username' => 'extra8', 'email' => 'extra8@machine.local', 'is_email_confirmed' => 1, 'password' => '']);
        $this->database()->table('secret_santa_participants')->insertOrIgnore([
            ['exchange_id' => 1, 'user_id' => 4, 'wishlist' => 'WISH-4', 'shipping_note' => 'NOTE-4', 'joined_at' => Carbon::now()],
            ['exchange_id' => 1, 'user_id' => 8, 'wishlist' => 'WISH-8', 'shipping_note' => 'NOTE-8', 'joined_at' => Carbon::now()],
        ]);
        $this->database()->table('secret_santa_assignments')->delete();
        foreach ([[2, 3], [3, 4], [4, 5], [5, 6], [6, 8], [8, 2]] as [$g, $r]) {
            $this->database()->table('secret_santa_assignments')->insert(['exchange_id' => 1, 'giver_id' => $g, 'receiver_id' => $r]);
        }
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['status' => 'active', 'revealed_at' => Carbon::now()]);
    }

    #[Test]
    public function the_rescue_santa_is_random_and_never_the_dropouts_santa()
    {
        $seen = [];
        for ($i = 0; $i < 24; $i++) {
            $this->fixedCycle();
            // 4 drops: their Santa 3 is let off; 5 needs a Santa from {2, 6, 8}, and 6 is 5's
            // own giftee, so 2 or 8, at random.
            $this->app()->getContainer()->make(\ErnestDefoe\SecretSanta\Dropouts::class)->remove(\ErnestDefoe\SecretSanta\Exchange::query()->find(1), 4);
            $seen[$this->giverOf(5)] = true;
        }
        ksort($seen);
        $this->assertSame([2, 8], array_keys($seen));
    }

    #[Test]
    public function a_dropout_that_would_leave_three_is_refused()
    {
        $this->draw();
        [$status] = $this->call('POST', '/api/secret-santa/1/dropout', 2, ['user' => 6]);
        $this->assertSame(200, $status);
        [$status, $json] = $this->call('POST', '/api/secret-santa/1/dropout', 2, ['user' => 5]);
        $this->assertSame('secret_santa_too_few_left', $json['errors'][0]['code']);
        $this->assertSame(4, $this->database()->table('secret_santa_participants')->count());
    }

    #[Test]
    public function a_rescue_santa_who_drops_out_strands_neither_giftee()
    {
        // 6 more members, so 2..11 in all; 2 owes gifts to 3 (drawn) and 9 (a rescue).
        foreach (range(8, 11) as $id) {
            $this->database()->table('users')->insert(['id' => $id, 'username' => "extra$id", 'email' => "extra$id@machine.local", 'is_email_confirmed' => 1, 'password' => '']);
            $this->database()->table('secret_santa_participants')->insert(['exchange_id' => 1, 'user_id' => $id, 'wishlist' => "WISH-$id", 'joined_at' => Carbon::now()]);
        }
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['status' => 'active']);
        foreach ([[2, 3], [3, 4], [4, 5], [5, 6], [6, 8], [8, 10], [10, 11], [11, 2], [2, 9]] as [$g, $r]) {
            $this->database()->table('secret_santa_assignments')->insert(['exchange_id' => 1, 'giver_id' => $g, 'receiver_id' => $r]);
        }

        [$status] = $this->call('POST', '/api/secret-santa/1/dropout', 1, ['user' => 2]);
        $this->assertSame(200, $status);
        foreach ([3, 9] as $giftee) {
            $this->assertNotContains($this->giverOf($giftee), [0, 2, 11], "giftee $giftee has a new Santa, not the dropout's own Santa");
        }
    }

    #[Test]
    public function a_giftee_left_waiting_gets_a_rescue_santa_from_the_exchange()
    {
        $pairs = $this->draw();
        // Nobody eligible was found for 5 (simulated): they wait for the organizer.
        $this->database()->table('secret_santa_assignments')->where('receiver_id', 5)->delete();
        [, $json] = $this->call('GET', '/api/secret-santa/1', 2);
        $waiting = array_values(array_filter($json['data']['organizing']['members'], fn ($row) => $row['waiting']));
        $this->assertSame([5], array_map(fn ($row) => $row['user']['id'], $waiting));

        [, $view] = $this->call('GET', '/api/secret-santa/1', 5);
        $this->assertTrue($view['data']['receiving']['waiting']);

        // Someone outside the exchange can't be handed the giftee's address.
        [$status] = $this->call('POST', '/api/secret-santa/1/rescue', 2, ['user' => 5, 'volunteer' => 7]);
        $this->assertSame(422, $status);

        [$status] = $this->call('POST', '/api/secret-santa/1/rescue', 2, ['user' => 5, 'volunteer' => 6]);
        $this->assertSame(200, $status);
        $this->assertSame(6, $this->giverOf(5));
    }

    #[Test]
    public function finishing_tells_everyone_and_the_list_moves_it_to_past()
    {
        $this->draw();
        [$status, $json] = $this->call('POST', '/api/secret-santa/1/finish', 2);
        $this->assertSame('complete', $json['data']['status']);
        $this->assertSame(5, $this->database()->table('notifications')->where('type', 'secretSantaNews')->count());

        [, $list] = $this->call('GET', '/api/secret-santa', 3);
        $this->assertSame([], $list['current']);
        $this->assertSame(1, $list['past'][0]['id']);
        $this->assertTrue($list['past'][0]['joined']);
    }

    #[Test]
    public function the_list_is_a_fixed_number_of_queries()
    {
        $counts = [];
        foreach ([1, 12] as $n) {
            $this->database()->table('secret_santa_exchanges')->where('id', '>', 1)->delete();
            for ($i = 2; $i <= $n; $i++) {
                $this->database()->table('secret_santa_exchanges')->insert(['id' => $i, 'user_id' => 7, 'title' => "Swap $i", 'kind' => 'digital', 'status' => 'open', 'signup_ends_at' => Carbon::now()->addDays($i), 'hold_reveal' => 0, 'unmask' => 1, 'created_at' => Carbon::now()]);
                $this->database()->table('secret_santa_participants')->insert(['exchange_id' => $i, 'user_id' => 3, 'wishlist' => 'x', 'joined_at' => Carbon::now()]);
            }
            $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['status' => 'complete', 'completed_at' => Carbon::now()]);

            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [, $list] = $this->call('GET', '/api/secret-santa', 3);
            $counts[$n] = count(array_filter($db->getQueryLog(), fn ($q) => str_contains($q['query'], 'secret_santa') || str_contains($q['query'], 'users')));
            $db->disableQueryLog();
            $this->assertCount($n - 1, $list['current']);
        }
        $this->assertSame($counts[1], $counts[12]);
    }
}
