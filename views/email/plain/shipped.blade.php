<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-secret-santa.email.shipped.plain', [
    '{recipient_display_name}' => $user->display_name,
    '{title}' => $blueprint->exchange->title,
    '{giftee_display_name}' => $blueprint->subject->display_name,
    '{url}' => $url->to('forum')->route('secret-santa.exchange', ['id' => $blueprint->exchange->id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
