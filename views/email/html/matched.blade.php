<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-secret-santa.email.matched.body', [
            '{recipient_display_name}' => e($user->display_name),
            '{title}' => e($blueprint->exchange->title),
            '{giftee_display_name}' => e($blueprint->subject->display_name),
            '{url}' => e($url->to('forum')->route('secret-santa.exchange', ['id' => $blueprint->exchange->id])),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
