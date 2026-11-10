# Secret Santa: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- 🚨 **Privacy is the product.** Matches (`secret_santa_assignments`), wishlists, shipping notes, gift notes, thanks and messages leave the server ONLY through `Presenter`, per viewer. Never add a JSON:API resource, relationship or `toArray()` path for them; the models' `$hidden` is only a backstop. Every new field that reaches the browser needs a line in `tests/integration/api/PrivacyTest.php`.
- Notifications: the subject and from-user are serialized with the notification. Anything a GIFTEE receives has the giftee as subject and **no from-user** (`ShippedBlueprint`, `MessageBlueprint` from the Santa). Every subject is a `User` (a subject model without an API resource 500s all notifications).
- Organizers see per-member flags (sent theirs / got theirs / waiting for a Santa), never pairs. The only way to see a pair is `peek`: a reason is required and every use is written to `secret_santa_reveals`, shown to all organizers.
- An organizer who is ALSO a member sees per-member "sent" flags only after the send-by date (or 3 weeks after the reveal): otherwise their own gift shipping at the moment one flag flips names their Santa (`Presenter::organizing`, `sentHiddenUntil`).
- The draw (`Matcher`) is one cycle through everyone, CSPRNG-shuffled (`Random\Randomizer`), exclusions symmetric; dense exclusions fall back to a bounded backtracking search. Fewer than 3 members, or no cycle, leaves the exchange open with `draw_error` set and alerts the organizer once (cleared by any edit or exclusion change).
- Draws happen in `secret-santa:draw` (scheduled every 5 minutes) AND lazily on the list/show endpoints (`Draw::due`): most forums have no cron. `run()` locks the row and re-checks the state, so two requests can't draw twice.
- `hold_reveal`: drawn → organizer may re-draw blind → `reveal` sends the Matched alerts. Re-drawing is only possible before the reveal.
- Dropouts after the reveal splice the cycle (the dropout's Santa takes over their giftee) unless a gift already shipped; a giftee left without a Santa shows as "waiting" and an organizer assigns a rescue Santa (that one pair is known to them, and the confirm says so). A receiver has at most one Santa (unique index); a giver may have several (rescues).
- The show-off discussion is started through Flarum's API as the organizer (`ShowOffThread`): process() skips `startDiscussion` and the tag check, so both are asked by hand.
- Pages and modals are one lazy chunk (`forum/components/pages`); forum.js only registers routes, the nav link and notification components. Extend core's lazy components by module name.
- All user text is rendered as plain text (white-space: pre-wrap), never HTML. Email bodies escape names with `e()` before the Markdown formatter.

## Testing

- `composer test:setup && composer test` (integration; SQLite and prefixed MySQL), `composer analyse:phpstan`.
- On this Mac, isolated tests need `PHP_INI_SCAN_DIR` with `memory_limit=1G` (see the traps index).
- `cd js && npm ci && npm run build && npm run format-check`.
