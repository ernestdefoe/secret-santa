<?php

namespace ErnestDefoe\SecretSanta\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;

/**
 * An exchange with members 2..(n+1). Every wishlist and note is a unique
 * marker (WISH-3, NOTE-3), so a test can search a whole response body for
 * anything it must not contain.
 */
abstract class SantaTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const MEMBERS = [2, 3, 4, 5, 6];

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-secret-santa');

        $users = [$this->normalUser()];
        foreach (array_slice(self::MEMBERS, 1) as $id) {
            $users[] = ['id' => $id, 'username' => "member$id", 'email' => "member$id@machine.local", 'is_email_confirmed' => 1];
        }
        $users[] = ['id' => 7, 'username' => 'outsider', 'email' => 'outsider@machine.local', 'is_email_confirmed' => 1];

        $this->prepareDatabase([
            User::class => $users,
            'group_user' => array_map(fn ($id) => ['user_id' => $id, 'group_id' => 3], array_merge(self::MEMBERS, [7])),
            'secret_santa_exchanges' => [
                // Organized by member 2; sign-ups closed a minute ago.
                ['id' => 1, 'user_id' => 2, 'title' => 'Holiday 2026', 'kind' => 'physical', 'status' => 'open', 'signup_ends_at' => Carbon::now()->subMinute(), 'hold_reveal' => 0, 'unmask' => 1, 'participants_count' => count(self::MEMBERS), 'created_at' => Carbon::now()],
            ],
            'secret_santa_participants' => array_map(fn ($id) => ['exchange_id' => 1, 'user_id' => $id, 'wishlist' => "WISH-$id", 'shipping_note' => "NOTE-$id", 'joined_at' => Carbon::now()], self::MEMBERS),
        ]);
    }

    /**
     * The draw, the reveal and the end tell each member something different
     * ("you're buying for Alice"), so Flarum's NotificationSyncer runs once per
     * member: a notification row, an unsubscribe token, and the queued email
     * job reloading its recipient. That is the feature, not an N+1 of ours.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['notifications', 'unsubscribe_tokens', 'users"."id" = ? limit ?', 'users`.`id` = ? limit ?'];
    }

    /** @return array{int, array<string, mixed>|null, string} */
    protected function call(string $method, string $path, ?int $as = null, array $json = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($json || $method !== 'GET') {
            $options['json'] = $json;
        }
        $response = $this->send($this->request($method, $path, $options));
        $body = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($body, true), $body];
    }

    /** giver => receiver, straight from the table. */
    protected function pairs(int $exchange = 1): array
    {
        return $this->database()->table('secret_santa_assignments')->where('exchange_id', $exchange)
            ->pluck('receiver_id', 'giver_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function giverOf(int $receiver, int $exchange = 1): int
    {
        return (int) $this->database()->table('secret_santa_assignments')->where('exchange_id', $exchange)->where('receiver_id', $receiver)->value('giver_id');
    }

    protected function gifteeOf(int $giver, int $exchange = 1): int
    {
        return (int) $this->database()->table('secret_santa_assignments')->where('exchange_id', $exchange)->where('giver_id', $giver)->value('receiver_id');
    }

    /** Markers that may appear in a body: everything else must not. */
    protected function assertOnlyMarkers(string $body, array $allowed, string $who): void
    {
        foreach (self::MEMBERS as $id) {
            foreach (["WISH-$id", "NOTE-$id"] as $marker) {
                if (in_array($marker, $allowed, true)) {
                    $this->assertStringContainsString($marker, $body, "$who should see $marker");
                } else {
                    $this->assertStringNotContainsString($marker, $body, "$who must not see $marker");
                }
            }
        }
    }
}
