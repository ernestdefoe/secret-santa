<?php

namespace ErnestDefoe\SecretSanta\Gdpr;

use ErnestDefoe\SecretSanta\Assignment;
use ErnestDefoe\SecretSanta\Exchange;
use ErnestDefoe\SecretSanta\Message;
use ErnestDefoe\SecretSanta\Participant;
use Flarum\Gdpr\Data\Type;

/**
 * A member's exchanges in a data export: their entries, whom they gave to,
 * and the gifts they got (but not who sent them, unless that exchange
 * unmasked its Santas). Gone on erasure. Registered only with flarum/gdpr.
 */
class SecretSantaData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-secret-santa.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-secret-santa.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $id = $this->user->id;
        $export = [];

        foreach (Participant::query()->where('user_id', $id)->get() as $entry) {
            /** @var Exchange|null $exchange */
            $exchange = Exchange::query()->find($entry->exchange_id);
            $gave = Assignment::query()->where('exchange_id', $entry->exchange_id)->where('giver_id', $id)->get();
            /** @var Assignment|null $got */
            $got = Assignment::query()->where('exchange_id', $entry->exchange_id)->where('receiver_id', $id)->first();
            $unmasked = $exchange && $exchange->status === Exchange::COMPLETE && $exchange->unmask;
            $mine = fn (Assignment $a, bool $asGiver) => Message::query()->where('assignment_id', $a->id)->where('from_giver', $asGiver)->pluck('body')->all();

            $export[] = ["secret-santa/exchange-{$entry->exchange_id}.json" => $this->encodeForExport([
                'exchange' => $exchange?->title,
                'wishlist' => $entry->wishlist,
                'shipping_note' => $entry->shipping_note,
                'joined_at' => $entry->joined_at,
                'gave_to' => $gave->map(fn (Assignment $a) => ['user_id' => $a->receiver_id, 'shipped_at' => $a->shipped_at, 'gift_note' => $a->gift_note, 'notes_you_wrote' => $mine($a, true)])->all(),
                'received' => $got ? [
                    'from_user_id' => $unmasked ? $got->giver_id : null,
                    'received_at' => $got->received_at,
                    'thanks' => $got->thanks,
                    'notes_you_wrote' => $mine($got, false),
                ] : null,
            ])];
        }

        return $export;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Participant::query()->where('user_id', $this->user->id)->delete();
        Assignment::query()->where('giver_id', $this->user->id)->orWhere('receiver_id', $this->user->id)->delete();
    }
}
