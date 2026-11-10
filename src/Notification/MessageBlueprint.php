<?php

namespace ErnestDefoe\SecretSanta\Notification;

use ErnestDefoe\SecretSanta\Exchange;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * A new anonymous note. To the giftee: subject the giftee, no from-user.
 * To the Santa: subject and from-user the giftee (the Santa knows them).
 */
class MessageBlueprint extends SantaBlueprint implements MailableInterface
{
    public function __construct(Exchange $exchange, User $giftee, bool $fromSanta, int $messageId)
    {
        parent::__construct($exchange, $giftee, $fromSanta ? null : $giftee, ['fromSanta' => $fromSanta, 'message' => $messageId]);
    }

    public static function getType(): string
    {
        return 'secretSantaMessage';
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-secret-santa::email.plain.message', 'html' => 'ernestdefoe-secret-santa::email.html.message'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-secret-santa.email.message.subject_'.($this->extra['fromSanta'] ? 'santa' : 'giftee'), ['{title}' => $this->exchange->title]);
    }
}
