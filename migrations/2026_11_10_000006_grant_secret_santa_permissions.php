<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Everyone may see the exchanges; members may join and start one; moderators may run any exchange.
return Migration::addPermissions([
    'secret-santa.view' => Group::GUEST_ID,
    'secret-santa.join' => Group::MEMBER_ID,
    'secret-santa.start' => Group::MEMBER_ID,
    'secret-santa.moderate' => Group::MODERATOR_ID,
]);
