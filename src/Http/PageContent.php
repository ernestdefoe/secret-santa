<?php

namespace ErnestDefoe\SecretSanta\Http;

use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Settings;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /secret-santa and /secret-santa/{id} as the server sends them: a title and a
 * canonical URL. Only public fields (the title) are ever put in the page.
 */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $title = (string) $this->translator->trans('ernestdefoe-secret-santa.forum.page.title');
        $id = (int) Arr::get($request->getQueryParams(), 'id');

        if ($id && RequestUtil::getActor($request)->can(Settings::VIEW) && ($exchange = Exchange::query()->find($id))) {
            $document->title = $exchange->title.' - '.$title;
            $document->canonicalUrl = $this->url->to('forum')->route('secret-santa.exchange', ['id' => $exchange->id]);
        } else {
            $document->title = $title;
            $document->canonicalUrl = $this->url->to('forum')->route('secret-santa');
        }

        return $document;
    }
}
