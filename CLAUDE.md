# Secret Santa: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- 🚨 **Privacy is the product.** Matches (`secret_santa_assignments`), wishlists, shipping notes, gift notes, thanks and messages leave the server ONLY through `Presenter`, per viewer. Never add a JSON:API resource, relationship or `toArray()` path for them; the models' `$hidden` is only a backstop. Every new field that reaches the browser needs a line in `tests/integration/api/PrivacyTest.php`.
- Notifications: the subject and from-user are serialized with the notification. Anything a GIFTEE receives has the giftee as subject and **no from-user** (`ShippedBlueprint`, `MessageBlueprint` from the Santa). Every subject is a `User` (a subject model without an API resource 500s all notifications).
- Organizers see per-member flags (sent theirs / got theirs / waiting for a Santa), never pairs. The only way to see a pair is `peek`: a reason is required and every use is written to `secret_santa_reveals`, shown to all organizers.
- Per-member "sent" flags stay hidden from EVERY organizer until `sent_visible_at`, fixed at the reveal (`Draw::sentVisibleAt`: the send-by date, never under two weeks). Otherwise an organizer in the exchange sees their own gift ship as one flag flips, and any organizer can line flags up with what members post. Once sign-ups close the send-by date may only move later and `unmask` is settled.
- 🚨 No row id (assignment, message) ever reaches the browser, in or out: ids follow insertion order. Santas name their giftee by user id, giftees say `to: santa`. Draw inserts rows in a separately shuffled order.
- The draw (`Matcher`) is one cycle through everyone, CSPRNG-shuffled (`Random\Randomizer`), exclusions symmetric; dense exclusions fall back to a bounded backtracking search. 🚨 At least 4 members (`MIN_MEMBERS`: with 3, A buys for B so C is A's Santa) and nobody with under 3 allowed neighbours (`MIN_NEIGHBOURS`, `Matcher::cornered`), enforced on every exclusion, member's or organizer's, and again at the draw. A failed draw leaves the exchange open with `draw_error` (`too_few`, `exclusions`, or `busy` when the step budget ran out) and alerts the organizer once (cleared by any edit or exclusion change).
- Draws happen in `secret-santa:draw` (scheduled every 5 minutes) AND lazily on the list/show endpoints (`Draw::due`): most forums have no cron. `run()` locks the row and re-checks the state, so two requests can't draw twice. Anything else that deletes or rewires assignments (dropouts, exclusions, leaving, rescues) runs inside `Exchange::locked` and re-reads the status. The alerts to everyone (matches out, exchange over) go on the queue (`Job\SendNews`), never inside a visitor's request.
- `hold_reveal`: drawn → organizer may re-draw blind → `reveal` sends the Matched alerts. Re-drawing is only possible before the reveal.
- Dropouts after the reveal (`Dropouts`): the dropout's own Santa is LET OFF, never handed the dropout's giftee (they'd learn the dropout's pair); every gift the dropout still owed goes to a random rescue Santa (never the dropout's Santa, not excluded, least-loaded, not the giftee's own giftee if avoidable), so the organizer view shows nobody waiting. Only when nobody is eligible does a giftee wait for an organizer's rescue (that pair is known to them). Refused when it would leave under 4 members. A receiver has at most one Santa (unique index); a giver may have several (rescues).
- The show-off discussion is started through Flarum's API as the organizer (`ShowOffThread`): process() skips `startDiscussion` and the tag check, so both are asked by hand.
- Pages and modals are one lazy chunk (`forum/components/pages`); forum.js only registers routes, the nav link and notification components. Extend core's lazy components by module name.
- Index names are kept under MySQL's 64 characters behind a 9-character prefix (`HardeningTest`); name any new long index explicitly.
- All user text is rendered as plain text (white-space: pre-wrap), never HTML. Email bodies escape names with `e()` before the Markdown formatter.

## Testing

- `composer test:setup && composer test` (integration; SQLite and prefixed MySQL), `composer analyse:phpstan`.
- On this Mac, isolated tests need `PHP_INI_SCAN_DIR` with `memory_limit=1G` (see the traps index).
- `cd js && npm ci && npm run build && npm run format-check`.
