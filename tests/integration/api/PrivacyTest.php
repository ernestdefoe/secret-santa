<?php

namespace ErnestDefoe\SecretSanta\Tests\integration\api;

use ErnestDefoe\SecretSanta\Tests\integration\SantaTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The point of the extension: each member sees only their own match, and
 * nobody else (admins, organizers, guests) sees any pair, wishlist or
 * shipping note, by any API path, unless they use the logged organizer reveal.
 */
class PrivacyTest extends SantaTestCase
{
    private function drawn(): array
    {
        // Reading the list draws exchanges whose sign-ups have closed.
        $this->call('GET', '/api/secret-santa', 7);
        $pairs = $this->pairs();
        $this->assertCount(count(self::MEMBERS), $pairs);

        return $pairs;
    }

    #[Test]
    public function each_member_sees_only_their_own_giftee_and_their_notes()
    {
        $pairs = $this->drawn();

        foreach (self::MEMBERS as $me) {
            [$status, $json, $body] = $this->call('GET', '/api/secret-santa/1', $me);
            $this->assertSame(200, $status);
            $giftee = $pairs[$me];

            $this->assertCount(1, $json['data']['giving']);
            $this->assertSame($giftee, $json['data']['giving'][0]['user']['id']);
            $this->assertSame("NOTE-$giftee", $json['data']['giving'][0]['shippingNote']);
            $this->assertNull($json['data']['receiving']['santa'], 'the Santa stays secret');
            $this->assertSame(['shippedAt', 'giftNote', 'receivedAt', 'thanks', 'messages', 'santa'], array_keys($json['data']['receiving']));
            $this->assertOnlyMarkers($body, ["WISH-$me", "NOTE-$me", "WISH-$giftee", "NOTE-$giftee"], "member $me");
        }
    }

    #[Test]
    public function an_admin_who_is_not_in_it_sees_no_pair_wishlist_or_note()
    {
        $this->drawn();

        [$status, $json, $body] = $this->call('GET', '/api/secret-santa/1', 1);
        $this->assertSame(200, $status);
        $this->assertSame([], $json['data']['giving']);
        $this->assertNull($json['data']['receiving']);
        $this->assertNull($json['data']['me']);
        $this->assertOnlyMarkers($body, [], 'admin');

        // The admin runs it (moderate permission), and the organizer view holds flags, never pairs.
        $this->assertNotNull($json['data']['organizing']);
        $this->assertArrayNotHasKey('pairs', $json['data']['organizing']);
        foreach ($json['data']['organizing']['members'] as $row) {
            $this->assertSame(['user', 'joinedAt', 'hasShippingNote', 'shipped', 'received', 'waiting'], array_keys($row));
        }
    }

    #[Test]
    public function an_organizer_in_the_exchange_cannot_work_out_their_santa_from_who_has_sent()
    {
        $this->drawn();
        $santa = $this->giverOf(2);
        $this->call('POST', '/api/secret-santa/1/shipped', $santa, ['giftee' => $this->gifteeOf($santa)]);

        // Member 2 organizes and takes part: no per-member sent flags before the send-by date.
        [, $json] = $this->call('GET', '/api/secret-santa/1', 2);
        $this->assertNotNull($json['data']['organizing']['sentHiddenUntil']);
        foreach ($json['data']['organizing']['members'] as $row) {
            $this->assertNull($row['shipped']);
        }

        // Once the moment fixed at the reveal has passed, they show.
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['sent_visible_at' => \Carbon\Carbon::now()->subMinute()]);
        [, $json] = $this->call('GET', '/api/secret-santa/1', 2);
        $this->assertNull($json['data']['organizing']['sentHiddenUntil']);
    }

    #[Test]
    public function the_organizer_sees_only_their_own_match()
    {
        $pairs = $this->drawn();
        [, $json, $body] = $this->call('GET', '/api/secret-santa/1', 2);

        $this->assertNotNull($json['data']['organizing']);
        $this->assertOnlyMarkers($body, ['WISH-2', 'NOTE-2', 'WISH-'.$pairs[2], 'NOTE-'.$pairs[2]], 'organizer');
    }

    #[Test]
    public function guests_and_outsiders_see_the_exchange_but_nothing_private()
    {
        $this->drawn();

        foreach ([null, 7] as $who) {
            [$status, $json, $body] = $this->call('GET', '/api/secret-santa/1', $who);
            $this->assertSame(200, $status);
            $this->assertCount(count(self::MEMBERS), $json['data']['participants']);
            $this->assertNull($json['data']['organizing']);
            $this->assertOnlyMarkers($body, [], $who ? 'outsider' : 'guest');
        }

        [, , $list] = $this->call('GET', '/api/secret-santa', null);
        $this->assertOnlyMarkers($list, [], 'the list');
    }

    #[Test]
    public function core_api_paths_and_includes_carry_nothing_of_it()
    {
        $pairs = $this->drawn();
        $giver = 3;
        $this->call('POST', '/api/secret-santa/1/shipped', $giver, ['giftee' => $this->gifteeOf($giver), 'note' => 'GIFTNOTE']);
        $this->call('POST', '/api/secret-santa/1/message', $giver, ['giftee' => $this->gifteeOf($giver), 'body' => 'SANTAMSG']);

        $paths = [
            '/api',
            '/api/users',
            '/api/users?include=groups',
            '/api/users/3',
            '/api/users/'.$pairs[3],
            '/api/notifications?include=fromUser,subject',
            '/api/discussions',
        ];
        foreach ([1, 2, $pairs[3]] as $who) {
            foreach ($paths as $path) {
                [, , $body] = $this->call('GET', $path, $who);
                $this->assertOnlyMarkers($body, [], "$path as $who");
                $this->assertStringNotContainsString('GIFTNOTE', $body);
                $this->assertStringNotContainsString('SANTAMSG', $body);
                $this->assertStringNotContainsString('giver', $body);
            }
        }
    }

    #[Test]
    public function a_giftees_notifications_never_name_their_santa()
    {
        $pairs = $this->drawn();
        $giver = 3;
        $giftee = $pairs[$giver];
        $this->call('POST', '/api/secret-santa/1/shipped', $giver, ['giftee' => $giftee]);
        $this->call('POST', '/api/secret-santa/1/message', $giver, ['giftee' => $giftee, 'body' => 'hello']);

        $rows = $this->database()->table('notifications')->where('user_id', $giftee)->whereIn('type', ['secretSantaShipped', 'secretSantaMessage'])->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->from_user_id);
            $this->assertSame($giftee, (int) $row->subject_id);
            $data = json_decode($row->data, true);
            // MySQL's JSON column reorders keys: compare the set.
            $this->assertEqualsCanonicalizing($row->type === 'secretSantaShipped' ? ['exchange', 'title'] : ['exchange', 'title', 'fromSanta', 'message'], array_keys($data));
        }

        [, $json] = $this->call('GET', '/api/notifications?include=fromUser,subject', $giftee);
        $checked = 0;
        foreach ($json['data'] as $notification) {
            if (in_array($notification['attributes']['contentType'], ['secretSantaShipped', 'secretSantaMessage'], true)) {
                $this->assertEmpty($notification['relationships']['fromUser']['data'] ?? null);
                $this->assertSame((string) $giftee, $notification['relationships']['subject']['data']['id']);
                $checked++;
            }
        }
        $this->assertSame(2, $checked);
        foreach ($json['included'] ?? [] as $included) {
            $this->assertFalse($included['type'] === 'users' && (int) $included['id'] === $giver, 'the Santa reached the giftee through an include');
        }
    }

    #[Test]
    public function nobody_sees_matches_before_they_are_sent_out()
    {
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['hold_reveal' => 1]);
        $this->drawn();
        $this->assertSame('drawn', $this->database()->table('secret_santa_exchanges')->where('id', 1)->value('status'));

        foreach (array_merge(self::MEMBERS, [1]) as $who) {
            [, $json, $body] = $this->call('GET', '/api/secret-santa/1', $who);
            $this->assertSame([], $json['data']['giving'], "member $who before the reveal");
            $this->assertNull($json['data']['receiving']);
            $allowed = in_array($who, self::MEMBERS, true) ? ["WISH-$who", "NOTE-$who"] : [];
            $this->assertOnlyMarkers($body, $allowed, "$who before the reveal");
        }
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'secretSantaMatched')->count());
    }

    #[Test]
    public function the_organizer_reveal_needs_a_reason_is_logged_and_is_organizers_only()
    {
        $pairs = $this->drawn();

        [$status] = $this->call('POST', '/api/secret-santa/1/peek', 3, ['user' => 4, 'reason' => 'curious']);
        $this->assertSame(403, $status, 'a member who is not the organizer');

        [$status] = $this->call('POST', '/api/secret-santa/1/peek', 2, ['user' => 4]);
        $this->assertSame(422, $status, 'no reason');
        $this->assertSame(0, $this->database()->table('secret_santa_reveals')->count());

        [$status, $json] = $this->call('POST', '/api/secret-santa/1/peek', 2, ['user' => 4, 'reason' => 'gift never arrived']);
        $this->assertSame(200, $status);
        $this->assertSame($this->giverOf(4), $json['santa']['id']);
        $this->assertSame($pairs[4], $json['giftees'][0]['id']);

        $log = $this->database()->table('secret_santa_reveals')->first();
        $this->assertSame([2, 4, 'gift never arrived'], [(int) $log->actor_id, (int) $log->user_id, $log->reason]);
        [, $show] = $this->call('GET', '/api/secret-santa/1', 1);
        $this->assertSame('gift never arrived', $show['data']['organizing']['reveals'][0]['reason']);
    }

    #[Test]
    public function santas_are_unmasked_only_to_their_own_giftee_and_only_when_it_ends()
    {
        $pairs = $this->drawn();
        $this->call('POST', '/api/secret-santa/1/finish', 2);

        foreach (self::MEMBERS as $me) {
            [, $json] = $this->call('GET', '/api/secret-santa/1', $me);
            $this->assertSame($this->giverOf($me), $json['data']['receiving']['santa']['id']);
        }
        [, $outsider, $body] = $this->call('GET', '/api/secret-santa/1', 7);
        $this->assertNull($outsider['data']['receiving']);
        $this->assertOnlyMarkers($body, [], 'outsider after the end');

        // An exchange that doesn't unmask keeps its Santas secret for good.
        $this->database()->table('secret_santa_exchanges')->where('id', 1)->update(['unmask' => 0]);
        [, $json] = $this->call('GET', '/api/secret-santa/1', 3);
        $this->assertNull($json['data']['receiving']['santa']);
    }

    #[Test]
    public function members_cannot_act_on_someone_elses_assignment()
    {
        $pairs = $this->drawn();

        // 3 names 4's giftee, who isn't theirs (each giftee has one Santa): nothing happens.
        foreach (['shipped', 'message'] as $action) {
            [$status] = $this->call('POST', "/api/secret-santa/1/$action", 3, ['giftee' => $pairs[4], 'body' => 'x']);
            $this->assertSame(404, $status, $action);
        }
        // Someone outside the exchange has no gift to receive and no Santa to write to.
        [$status] = $this->call('POST', '/api/secret-santa/1/received', 7);
        $this->assertSame(404, $status);
        [$status] = $this->call('POST', '/api/secret-santa/1/message', 7, ['to' => 'santa', 'body' => 'hi']);
        $this->assertSame(404, $status);
    }
}
