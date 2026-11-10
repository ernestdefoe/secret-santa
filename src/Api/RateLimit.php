<?php

namespace ErnestDefoe\SecretSanta\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/** How many of a thing a member may do in a day: exchanges, notes. Core's flood control never sees these. */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor, string $kind, int $max): bool
    {
        $key = 'secret-santa:rate:'.$kind.':'.$actor->id.':'.intdiv(time(), 86400);
        $this->cache->add($key, 0, 90000);

        return (int) $this->cache->increment($key) > $max;
    }
}
