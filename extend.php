<?php

/*
 * Secret Santa: gift exchanges for the community.
 *
 * Privacy is the point. Who buys for whom, wishlists, shipping notes, gift
 * notes and Santa messages live in our own tables and leave the server only
 * through Presenter, per viewer. There is no JSON:API resource for any of it
 * (so no include or relationship path reaches it), and notifications a
 * giftee gets never carry their Santa (Notification\SantaBlueprint).
 *
 * The page, the forms and the exchange view are a lazy chunk: a forum page
 * that is not /secret-santa loads none of them.
 */

use ErnestDefoe\SecretSanta\Api;
use ErnestDefoe\SecretSanta\Console\DrawCommand;
use ErnestDefoe\SecretSanta\Gdpr\SecretSantaData;
use ErnestDefoe\SecretSanta\Http\PageContent;
use ErnestDefoe\SecretSanta\Notification;
use ErnestDefoe\SecretSanta\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the page is a chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/secret-santa', 'secret-santa', PageContent::class)
        ->route('/secret-santa/{id:\d+}', 'secret-santa.exchange', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-secret-santa', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/secret-santa', 'secret-santa.index', Api\ExchangeController::class)
        ->post('/secret-santa', 'secret-santa.create', Api\ExchangeController::class)
        ->get('/secret-santa/{id:\d+}', 'secret-santa.show', Api\ExchangeController::class)
        ->patch('/secret-santa/{id:\d+}', 'secret-santa.update', Api\ExchangeController::class)
        ->delete('/secret-santa/{id:\d+}', 'secret-santa.delete', Api\ExchangeController::class)
        ->post('/secret-santa/{id:\d+}/entry', 'secret-santa.entry.create', Api\EntryController::class)
        ->patch('/secret-santa/{id:\d+}/entry', 'secret-santa.entry.update', Api\EntryController::class)
        ->delete('/secret-santa/{id:\d+}/entry', 'secret-santa.entry.delete', Api\EntryController::class)
        ->post('/secret-santa/{id:\d+}/{action:[a-z]+}', 'secret-santa.action', Api\ActionController::class),

    (new Extend\Notification())
        ->type(Notification\MatchedBlueprint::class, ['alert', 'email'])
        ->type(Notification\ShippedBlueprint::class, ['alert', 'email'])
        ->type(Notification\MessageBlueprint::class, ['alert', 'email'])
        ->type(Notification\ReceivedBlueprint::class, ['alert'])
        ->type(Notification\JoinedBlueprint::class, ['alert'])
        ->type(Notification\NewsBlueprint::class, ['alert']),

    (new Extend\Console())
        ->command(DrawCommand::class)
        ->schedule(DrawCommand::class, function ($event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('secretSantaCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Boolean::make('secretSantaCanStart')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::START)),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->serializeToForum('secretSantaNav', Settings::NAV, 'boolval'),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(SecretSantaData::class),
        ]),
];
