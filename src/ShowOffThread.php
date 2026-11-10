<?php

namespace ErnestDefoe\SecretSanta;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The "show what you got" discussion: started by the organizer, as
 * themselves, through Flarum's own API, so every rule and extension applies.
 * process() skips the endpoint's own permission checks, so startDiscussion
 * and the tag's permission are asked here.
 */
class ShowOffThread
{
    public function __construct(
        private readonly JsonApi $api,
        private readonly ExtensionManager $extensions,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
    ) {
    }

    /** Null when started, else an error code. */
    public function start(Exchange $exchange, User $actor, ServerRequestInterface $request): ?string
    {
        if ($exchange->status === Exchange::OPEN) {
            return 'secret_santa_wrong_state';
        }
        if ($exchange->discussion_id && Discussion::query()->whereKey($exchange->discussion_id)->exists()) {
            return 'secret_santa_thread_exists';
        }
        if (! $actor->can('startDiscussion')) {
            return 'permission_denied';
        }

        $data = ['type' => 'discussions', 'attributes' => [
            'title' => mb_substr($this->translator->trans('ernestdefoe-secret-santa.lib.thread.title', ['{title}' => $exchange->title]), 0, 80),
            'content' => $this->translator->trans('ernestdefoe-secret-santa.lib.thread.body', [
                '{title}' => $exchange->title,
                '{url}' => $this->url->to('forum')->route('secret-santa.exchange', ['id' => $exchange->id]),
            ]),
        ]];

        if ($this->extensions->isEnabled('flarum-tags') && class_exists(\Flarum\Tags\Tag::class)) {
            $tag = (int) $this->settings->get(Settings::THREAD_TAG);
            $model = $tag ? \Flarum\Tags\Tag::whereVisibleTo($actor)->find($tag) : null;
            if ($model) {
                if (! $actor->can('startDiscussion', $model)) {
                    return 'secret_santa_tag_denied';
                }
                $data['relationships']['tags']['data'] = [['type' => 'tags', 'id' => (string) $model->id]];
            } elseif (! $actor->hasPermission('bypassTagCounts')) {
                return 'secret_santa_needs_tag';
            }
        }

        /** @var Discussion $discussion */
        $discussion = $this->api->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            // process() merges the base request's data into ours: start from an empty one.
            ->withRequest($request->withParsedBody([])->withQueryParams([]))
            ->process(['data' => $data], [], ['actor' => $actor]);

        $exchange->discussion_id = $discussion->id;
        $exchange->save();

        return null;
    }
}
