import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { call, exchangeUrl, t, tx, type Card, type Exchange, type Giving, type MemberRow, type Note, type Person, type Receiving } from '../api';
import { day, Face, Faces, kindIcon, moment, PersonLink, StatusPill, Text, when } from './bits';
import { EntryModal, ExchangeModal, NoteModal } from './modals';

/**
 * /secret-santa (the list) and /secret-santa/:id (one exchange). Both sit in
 * core's page structure with the index sidebar, like the discussion list.
 * Everything shown comes from our endpoints, already cut down to what the
 * viewer may see (Presenter on the server): nothing here hides anything.
 */

declare const flarum: any;
declare const m: Mithril.Static;

/** Core components these pages build on, loaded through the registry: some live in lazy chunks. */
let PageStructure: any;
let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

function newExchange(): void {
  app.modal.show(ExchangeModal, { onsave: (exchange: Exchange) => m.route.set(exchangeUrl(exchange.id)) });
}

/** What a card says about where an exchange is. */
function progress(card: Card | Exchange): Mithril.Children {
  if (card.status === 'open') {
    const closed = new Date(card.signupEndsAt) <= new Date();
    return (
      <span className="SecretSanta-when">
        <Icon name="far fa-clock" /> {t(closed ? 'card.drawing_soon' : 'card.closes', { time: when(card.signupEndsAt) })}
      </span>
    );
  }
  if (card.status === 'drawn') return <span className="SecretSanta-when">{t('card.drawn')}</span>;

  const total = card.participantsCount || 1;
  const pct = Math.round((card.receivedCount / total) * 100);
  return (
    <span className="SecretSanta-progress" title={tx('card.progress', { shipped: card.shippedCount, received: card.receivedCount, count: total })}>
      <span className="SecretSanta-bar">
        <span className="SecretSanta-barShipped" style={{ width: Math.round((card.shippedCount / total) * 100) + '%' }} />
        <span className="SecretSanta-barReceived" style={{ width: pct + '%' }} />
      </span>
      <span className="SecretSanta-progressText">{t('card.received', { received: card.receivedCount, count: total })}</span>
    </span>
  );
}

export class ListPage extends Page {
  current: Card[] = [];
  past: Card[] = [];
  more = false;
  canStart = false;
  loading = true;
  failed = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('secret-santa', tx('page.title'));
    this.bodyClass = 'App--secretSanta';
    this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(tx('page.title'));
    app.setTitleCount(0);
  }

  load(offset: number) {
    this.loading = true;
    call<{ current: Card[]; past: Card[]; more: boolean; canStart: boolean }>('GET', '', offset ? { offset: String(offset) } : undefined)
      .then((res) => {
        if (!offset) this.current = res.current;
        this.past = offset ? this.past.concat(res.past) : res.past;
        this.more = res.more;
        this.canStart = res.canStart;
      })
      .catch(() => (this.failed = true))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const open = this.current.filter((c) => c.status === 'open');
    const underway = this.current.filter((c) => c.status !== 'open');
    const empty = !this.loading && !this.current.length && !this.past.length;

    return (
      <PageStructure className="SecretSantaPage" sidebar={() => <IndexSidebar />}>
        <header className="SecretSanta-head">
          <div className="SecretSanta-headText">
            <h2 className="SecretSanta-title">
              <Icon name="fas fa-gift" /> {t('page.title')}
            </h2>
            <p className="SecretSanta-lead">{t('page.lead')}</p>
          </div>
          {this.canStart && (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={newExchange}>
              {t('page.start')}
            </Button>
          )}
        </header>

        {empty && (
          <div className="SecretSanta-empty">
            <Icon name="far fa-snowflake" />
            <p>{t(this.failed ? 'page.load_error' : 'page.empty')}</p>
            {this.canStart && !this.failed && (
              <Button className="Button" icon="fas fa-plus" onclick={newExchange}>
                {t('page.start_first')}
              </Button>
            )}
          </div>
        )}

        {this.section('open', open)}
        {this.section('underway', underway)}
        {this.section('past', this.past)}

        {this.loading ? (
          <LoadingIndicator />
        ) : (
          this.more && (
            <div className="SecretSanta-more">
              <Button className="Button" onclick={() => this.load(this.past.length)}>
                {t('page.load_more')}
              </Button>
            </div>
          )
        )}
      </PageStructure>
    );
  }

  section(key: string, cards: Card[]) {
    if (!cards.length) return null;
    return (
      <section className={'SecretSanta-section SecretSanta-section--' + key}>
        <h3 className="SecretSanta-sectionTitle">{t(`page.section_${key}`)}</h3>
        <ul className="SecretSanta-grid">{cards.map((card) => this.card(card))}</ul>
      </section>
    );
  }

  card(card: Card) {
    return (
      <li className={'SecretSanta-card SecretSanta-card--' + card.status} key={card.id}>
        <Link href={exchangeUrl(card.id)} className="SecretSanta-cardLink">
          <span className="SecretSanta-cardTop">
            <StatusPill status={card.status} />
            {card.joined && (
              <span className="SecretSanta-youre-in">
                <Icon name="fas fa-check" /> {t('card.joined')}
              </span>
            )}
          </span>
          <span className="SecretSanta-cardTitle">{card.title}</span>
          <span className="SecretSanta-meta">
            <span>
              <Icon name={kindIcon(card.kind)} /> {t(`kind.${card.kind}`)}
            </span>
            {card.budget && (
              <span>
                <Icon name="fas fa-tag" /> {card.budget}
              </span>
            )}
            {card.organizer && (
              <span className="SecretSanta-metaBy">
                <Icon name="fas fa-user" /> {card.organizer.name}
              </span>
            )}
          </span>
          {progress(card)}
          <span className="SecretSanta-cardFoot">
            <Faces people={card.faces} total={card.participantsCount} />
            <span className="SecretSanta-count">{t('card.members', { count: card.participantsCount })}</span>
          </span>
        </Link>
      </li>
    );
  }
}

const STEPS = ['open', 'drawn', 'active', 'complete'] as const;

export class ExchangePage extends Page {
  exchange: Exchange | null = null;
  missing = false;
  busy = '';
  drafts: Record<string, Stream<string>> = {};
  rescueFor: Record<number, Stream<string>> = {};
  pairA = Stream('');
  pairB = Stream('');
  /** The last organizer reveal's answer, shown until dismissed (and gone on leaving the page). */
  peeked: { person: Person; santa: Person | null; giftees: Person[] } | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--secretSanta';
    const id = parseInt(m.route.param('id'), 10);
    app.history.push('secret-santa', tx('page.title'), app.route('secret-santa'));
    call<{ data: Exchange }>('GET', `/${id}`)
      .then((res) => this.set(res.data))
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  set(exchange: Exchange) {
    this.exchange = exchange;
    app.setTitle(exchange.title);
    app.setTitleCount(0);
  }

  /** Any action on this exchange: the answer is the exchange again, as we may see it. */
  act(action: string, body: Record<string, any> = {}, confirmText?: string): Promise<void> {
    if (confirmText && !confirm(confirmText)) return Promise.resolve();
    this.busy = action;
    m.redraw();
    return call<{ data: Exchange }>('POST', `/${this.exchange!.id}/${action}`, body)
      .then((res) => this.set(res.data))
      .finally(() => {
        this.busy = '';
        m.redraw();
      });
  }

  view() {
    const e = this.exchange;
    return (
      <PageStructure className="SecretSantaPage SecretSantaExchangePage" sidebar={() => <IndexSidebar />} loading={!e && !this.missing}>
        <Link className="SecretSanta-back" href={app.route('secret-santa')}>
          <Icon name="fas fa-arrow-left" /> {t('exchange.back')}
        </Link>
        {this.missing ? <p className="SecretSanta-empty">{t('exchange.missing')}</p> : e && this.content(e)}
      </PageStructure>
    );
  }

  content(e: Exchange) {
    return [
      <header className="SecretSanta-exchangeHead">
        <div className="SecretSanta-exchangeHeadTop">
          <StatusPill status={e.status} />
          {e.organizer && (
            <span className="SecretSanta-by">
              {t('exchange.organized_by')} <PersonLink person={e.organizer} />
            </span>
          )}
        </div>
        <h2 className="SecretSanta-exchangeTitle">{e.title}</h2>
        {this.timeline(e)}
      </header>,
      this.facts(e),
      // Once matches are out, who you buy for and your own gift come first.
      ...e.giving.map((g) => this.giving(e, g)),
      e.receiving && this.receiving(e, e.receiving),
      e.giving.length || e.receiving ? [this.mine(e), this.rules(e)] : [this.rules(e), this.mine(e)],
      this.thread(e),
      this.people(e),
      e.organizing && this.organizer(e),
    ];
  }

  rules(e: Exchange) {
    return (
      e.description && (
        <section className="SecretSanta-panel">
          <h3 className="SecretSanta-panelTitle">{t('exchange.rules')}</h3>
          <Text text={e.description} />
        </section>
      )
    );
  }

  timeline(e: Exchange) {
    const at = STEPS.indexOf(e.status);
    const dates: Record<string, Mithril.Children> = {
      // The dates live here only: sign-ups close at a time of day, the send-by date is a day.
      open: <time datetime={e.signupEndsAt}>{moment(e.signupEndsAt)}</time>,
      drawn: e.drawnAt ? day(e.drawnAt) : null,
      active: e.shipBy ? tx('exchange.ship_by_short', { date: day(e.shipBy) }) : null,
      complete: e.completedAt ? day(e.completedAt) : null,
    };
    return (
      <ol className="SecretSanta-timeline">
        {STEPS.map((step, i) => (
          <li className={'SecretSanta-step' + (i < at ? ' is-done' : i === at ? ' is-current' : '')} aria-current={i === at ? 'step' : undefined}>
            <span className="SecretSanta-stepDot">{i < at ? <Icon name="fas fa-check" /> : i + 1}</span>
            <span className="SecretSanta-stepLabel">{t(`step.${step}`)}</span>
            {dates[step] && <span className="SecretSanta-stepDate">{dates[step]}</span>}
          </li>
        ))}
      </ol>
    );
  }

  facts(e: Exchange) {
    const facts: [string, Mithril.Children, Mithril.Children][] = [
      ['fas fa-tag', t('exchange.budget'), e.budget || t('exchange.no_budget')],
      [kindIcon(e.kind), t('exchange.kind'), t(`kind.${e.kind}`)],
      ['fas fa-users', t('exchange.members'), String(e.participantsCount)],
    ];
    return (
      <dl className="SecretSanta-facts">
        {facts.map(([icon, label, value]) => (
          <div className="SecretSanta-fact">
            <dt>
              <Icon name={icon} /> {label}
            </dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    );
  }

  /** Joining, or the member's own entry. */
  mine(e: Exchange) {
    const edit = () => app.modal.show(EntryModal, { exchange: e, onsave: (x: Exchange) => this.set(x) });

    if (!e.me) {
      if (e.can.join) {
        return (
          <section className="SecretSanta-panel SecretSanta-join">
            <div className="SecretSanta-joinText">
              <h3 className="SecretSanta-panelTitle">{t('exchange.join_title')}</h3>
              <p>{t('exchange.join_help', { time: when(e.signupEndsAt) })}</p>
            </div>
            <Button className="Button Button--primary" icon="fas fa-gift" onclick={edit}>
              {t('exchange.join')}
            </Button>
          </section>
        );
      }
      // Unconfirmed members are guests to Flarum's permissions, so say why there's no Join button.
      const user = app.session.user;
      if (e.status === 'open' && (!user || !user.isEmailConfirmed())) {
        return (
          <section className="SecretSanta-panel SecretSanta-join">
            <p>{t(user ? 'exchange.confirm_email_to_join' : 'exchange.log_in_to_join')}</p>
          </section>
        );
      }
      return null;
    }

    const excluded = e.participants.filter((p) => e.me!.exclusions.includes(p.id));
    return (
      <section className="SecretSanta-panel SecretSanta-mine">
        <div className="SecretSanta-panelHead">
          <h3 className="SecretSanta-panelTitle">
            <Icon name="fas fa-circle-check" /> {t('exchange.you_are_in')}
          </h3>
          <div className="SecretSanta-panelActions">
            {e.status !== 'complete' && (
              <Button className="Button" icon="fas fa-pen" onclick={edit}>
                {t('exchange.edit_entry')}
              </Button>
            )}
            {e.can.leave && (
              <Button
                className="Button Button--danger"
                icon="fas fa-right-from-bracket"
                onclick={() =>
                  confirm(tx('exchange.leave_confirm')) &&
                  call<{ data: Exchange }>('DELETE', `/${e.id}/entry`)
                    .then((res) => this.set(res.data))
                    .catch(() => {})
                }
              >
                {t('exchange.leave')}
              </Button>
            )}
          </div>
        </div>
        {e.status === 'open' && <p className="helpText">{t('exchange.you_are_in_help')}</p>}
        {e.status === 'drawn' && <p className="SecretSanta-callout">{t('exchange.drawn_waiting')}</p>}
        <div className="SecretSanta-split">
          <div>
            <h4 className="SecretSanta-label">{t('exchange.your_wishlist')}</h4>
            <Text text={e.me.wishlist} className="SecretSanta-box" />
          </div>
          <div>
            <h4 className="SecretSanta-label">
              <Icon name="fas fa-lock" /> {t(`exchange.your_note_${e.kind}`)}
            </h4>
            {e.me.shippingNote ? (
              <Text text={e.me.shippingNote} className="SecretSanta-box" />
            ) : (
              <p className="SecretSanta-muted">{t('exchange.no_note')}</p>
            )}
            <p className="helpText">{t('exchange.note_private')}</p>
          </div>
        </div>
        {excluded.length > 0 && (
          <p className="SecretSanta-muted">
            {t('exchange.your_exclusions')} {excluded.map((p) => p.name).join(', ')}
          </p>
        )}
      </section>
    );
  }

  /** "You're buying for ...": the giftee, their wishlist and note, shipping, and the anonymous notes. */
  giving(e: Exchange, g: Giving) {
    const ship = () =>
      app.modal.show(NoteModal, {
        heading: t('giving.ship_title'),
        intro: t(`giving.ship_intro_${e.kind}`),
        label: t(`giving.gift_note_${e.kind}`),
        placeholder: tx(`giving.gift_note_placeholder_${e.kind}`),
        submit: t(`giving.ship_${e.kind}`),
        onsubmit: (note: string) => this.act('shipped', { giftee: g.user?.id, note }),
      });

    return (
      <section className="SecretSanta-panel SecretSanta-giving">
        <div className="SecretSanta-match">
          {g.user && <Face person={g.user} size="large" />}
          <div>
            <p className="SecretSanta-matchLabel">{t('giving.you_buy_for')}</p>
            <h3 className="SecretSanta-matchName">
              <PersonLink person={g.user} />
            </h3>
          </div>
        </div>
        <div className="SecretSanta-split">
          <div>
            <h4 className="SecretSanta-label">{t('giving.wishlist')}</h4>
            <Text text={g.wishlist} className="SecretSanta-box" />
          </div>
          <div>
            <h4 className="SecretSanta-label">
              <Icon name="fas fa-lock" /> {t(`giving.note_${e.kind}`)}
            </h4>
            {g.shippingNote ? <Text text={g.shippingNote} className="SecretSanta-box" /> : <p className="SecretSanta-muted">{t('giving.no_note')}</p>}
            <p className="helpText">{t('giving.note_private')}</p>
          </div>
        </div>

        <div className="SecretSanta-delivery">
          {g.receivedAt ? (
            <p className="SecretSanta-done">
              <Icon name="fas fa-box-open" /> {t('giving.received', { time: when(g.receivedAt) })}
            </p>
          ) : g.shippedAt ? (
            <p className="SecretSanta-done">
              <Icon name="fas fa-truck-fast" /> {t(`giving.shipped_${e.kind}`, { time: when(g.shippedAt) })}
            </p>
          ) : (
            <p className="SecretSanta-muted">{e.shipBy ? t('giving.ship_by', { date: day(e.shipBy) }) : t('giving.not_shipped')}</p>
          )}
          {g.thanks && (
            <blockquote className="SecretSanta-thanks">
              <Icon name="fas fa-heart" /> <Text text={g.thanks} />
            </blockquote>
          )}
          {g.giftNote && (
            <p className="SecretSanta-muted">
              {t('giving.your_note')} {g.giftNote}
            </p>
          )}
          <div className="SecretSanta-panelActions">
            {!g.shippedAt ? (
              <Button className="Button Button--primary" icon="fas fa-truck-fast" loading={this.busy === 'shipped'} onclick={ship}>
                {t(`giving.ship_${e.kind}`)}
              </Button>
            ) : (
              !g.receivedAt && (
                <Button className="Button Button--link" onclick={() => this.act('shipped', { giftee: g.user?.id, undo: true }).catch(() => {})}>
                  {t('giving.undo_ship')}
                </Button>
              )
            )}
          </div>
        </div>

        {this.notes({ giftee: g.user?.id }, g.messages, 'giving')}
      </section>
    );
  }

  /** "Your gift": whether it's on the way, the Santa's note, received + thanks, and the notes. */
  receiving(e: Exchange, r: Receiving) {
    if (r.waiting) {
      return (
        <section className="SecretSanta-panel SecretSanta-receiving">
          <h3 className="SecretSanta-panelTitle">
            <Icon name="fas fa-hourglass-half" /> {t('receiving.title')}
          </h3>
          <p>{t('receiving.waiting')}</p>
        </section>
      );
    }

    const receive = () =>
      app.modal.show(NoteModal, {
        heading: t('receiving.received_title'),
        label: t('receiving.thanks'),
        placeholder: tx('receiving.thanks_placeholder'),
        submit: t('receiving.mark_received'),
        onsubmit: (thanks: string) => this.act('received', { thanks }),
      });

    const stage = r.receivedAt ? 2 : r.shippedAt ? 1 : 0;
    return (
      <section className="SecretSanta-panel SecretSanta-receiving">
        <h3 className="SecretSanta-panelTitle">
          <Icon name="fas fa-gift" /> {t('receiving.title')}
        </h3>
        {r.santa && (
          <div className="SecretSanta-match SecretSanta-unmasked">
            <Face person={r.santa} size="large" />
            <div>
              <p className="SecretSanta-matchLabel">{t('receiving.your_santa_was')}</p>
              <h3 className="SecretSanta-matchName">
                <PersonLink person={r.santa} />
              </h3>
            </div>
          </div>
        )}
        <ol className="SecretSanta-track">
          {['waiting', `on_the_way_${e.kind}`, 'arrived'].map((key, i) => (
            <li className={i < stage ? 'is-done' : i === stage ? 'is-current' : ''}>{t(`receiving.stage_${key}`)}</li>
          ))}
        </ol>
        {r.giftNote && (
          <div>
            <h4 className="SecretSanta-label">{t('receiving.santa_note')}</h4>
            <Text text={r.giftNote} className="SecretSanta-box" />
          </div>
        )}
        {r.thanks && (
          <p className="SecretSanta-muted">
            {t('receiving.your_thanks')} {r.thanks}
          </p>
        )}
        <div className="SecretSanta-panelActions">
          {!r.receivedAt ? (
            <Button className={'Button' + (r.shippedAt ? ' Button--primary' : '')} icon="fas fa-box-open" onclick={receive}>
              {t('receiving.mark_received')}
            </Button>
          ) : (
            <Button className="Button Button--link" onclick={() => this.act('received', { undo: true }).catch(() => {})}>
              {t('receiving.undo')}
            </Button>
          )}
        </div>
        {this.notes({ to: 'santa' }, r.messages || [], 'receiving')}
      </section>
    );
  }

  /** The anonymous notes between a Santa and their giftee. */
  /** `to` names the other end: { giftee: userId } for a Santa, { to: 'santa' } for a giftee. Never a row id. */
  notes(to: Record<string, any>, messages: Note[], side: 'giving' | 'receiving') {
    const draft = (this.drafts[JSON.stringify(to)] ??= Stream(''));
    const send = (ev: SubmitEvent) => {
      ev.preventDefault();
      if (!draft().trim()) return;
      this.act('message', { ...to, body: draft() })
        .then(() => draft(''))
        .catch(() => {});
    };
    return (
      <div className="SecretSanta-notes">
        <h4 className="SecretSanta-label">
          <Icon name="fas fa-user-secret" /> {t(`notes.title_${side}`)}
        </h4>
        {messages.length ? (
          <ul className="SecretSanta-noteList">
            {messages.map((msg) => (
              <li className={'SecretSanta-note' + (msg.mine ? ' is-mine' : '')}>
                <span className="SecretSanta-noteWho">
                  {msg.mine ? t('notes.you') : t(side === 'giving' ? 'notes.giftee' : 'notes.santa')} · {when(msg.at)}
                </span>
                <Text text={msg.body} />
              </li>
            ))}
          </ul>
        ) : (
          <p className="SecretSanta-muted">{t(`notes.empty_${side}`)}</p>
        )}
        <form className="SecretSanta-noteForm" onsubmit={send}>
          <textarea
            className="FormControl"
            rows={2}
            maxlength={1000}
            bidi={draft}
            placeholder={tx(`notes.placeholder_${side}`)}
            aria-label={tx(`notes.placeholder_${side}`)}
          />
          <Button className="Button" type="submit" icon="fas fa-paper-plane" loading={this.busy === 'message'} disabled={!draft().trim()}>
            {t('notes.send')}
          </Button>
        </form>
      </div>
    );
  }

  thread(e: Exchange) {
    if (e.discussion) {
      return (
        <section className="SecretSanta-panel SecretSanta-threadPanel">
          <Icon name="fas fa-camera" />
          <div>
            <h3 className="SecretSanta-panelTitle">{t('exchange.thread_title')}</h3>
            <Link href={app.route('discussion', { id: `${e.discussion.id}-${e.discussion.slug}` })}>{e.discussion.title}</Link>
          </div>
        </section>
      );
    }
    return null;
  }

  people(e: Exchange) {
    if (!e.participants.length) return null;
    return (
      <section className="SecretSanta-panel">
        <h3 className="SecretSanta-panelTitle">{t('exchange.whos_in', { count: e.participantsCount })}</h3>
        <ul className="SecretSanta-people">
          {e.participants.map((p) => (
            <li key={p.id}>
              <PersonLink person={p} />
            </li>
          ))}
        </ul>
      </section>
    );
  }

  // 🚨 No `bidi` on these selects: core's bidi walks a select's children as <option>s, and
  // options from .map() are a fragment, so it throws on every redraw.

  /** Organizer tools. Counts and flags, never a pair: the only way to see one is the logged reveal. */
  organizer(e: Exchange) {
    const o = e.organizing!;
    const edit = () => app.modal.show(ExchangeModal, { exchange: e, onsave: (x: Exchange) => this.set(x) });
    const remove = () =>
      confirm(tx('organizer.delete_confirm')) &&
      call('DELETE', `/${e.id}`)
        .then(() => m.route.set(app.route('secret-santa')))
        .catch(() => {});
    const busy = (a: string) => this.busy === a;
    const closed = new Date(e.signupEndsAt) <= new Date();

    return (
      <section className="SecretSanta-panel SecretSanta-organizer">
        <div className="SecretSanta-panelHead">
          <h3 className="SecretSanta-panelTitle">
            <Icon name="fas fa-wand-magic-sparkles" /> {t('organizer.title')}
          </h3>
          <div className="SecretSanta-panelActions">
            {e.status !== 'complete' && (
              <Button className="Button" icon="fas fa-pen" onclick={edit}>
                {t('organizer.edit')}
              </Button>
            )}
            {e.can.delete && (
              <Button className="Button Button--danger" icon="fas fa-trash" onclick={remove}>
                {t('organizer.delete')}
              </Button>
            )}
          </div>
        </div>

        <p className="SecretSanta-callout">{this.organizerState(e)}</p>

        <div className="SecretSanta-panelActions SecretSanta-organizerMain">
          {e.status === 'open' && (
            <Button
              className={'Button' + (closed ? ' Button--primary' : '')}
              icon="fas fa-shuffle"
              loading={busy('draw')}
              disabled={e.participantsCount < 3}
              onclick={() => this.act('draw', {}, closed ? undefined : tx('organizer.draw_early_confirm')).catch(() => {})}
            >
              {t('organizer.draw_now')}
            </Button>
          )}
          {e.status === 'drawn' && [
            <Button
              className="Button Button--primary"
              icon="fas fa-paper-plane"
              loading={busy('reveal')}
              onclick={() => this.act('reveal').catch(() => {})}
            >
              {t('organizer.reveal')}
            </Button>,
            <Button className="Button" icon="fas fa-shuffle" loading={busy('draw')} onclick={() => this.act('draw').catch(() => {})}>
              {t('organizer.redraw')}
            </Button>,
          ]}
          {e.status === 'active' && (
            <Button
              className="Button"
              icon="fas fa-flag-checkered"
              loading={busy('finish')}
              onclick={() => this.act('finish', {}, tx(e.unmask ? 'organizer.finish_confirm_unmask' : 'organizer.finish_confirm')).catch(() => {})}
            >
              {t('organizer.finish')}
            </Button>
          )}
          {e.can.startThread && (
            <Button className="Button" icon="fas fa-camera" loading={busy('thread')} onclick={() => this.act('thread').catch(() => {})}>
              {t('organizer.start_thread')}
            </Button>
          )}
        </div>

        {this.peeked && (
          <div className="SecretSanta-peeked" role="status">
            <Icon name="fas fa-eye" />
            <span>
              {t('organizer.peek_result', {
                name: this.peeked.person.name,
                santa: this.peeked.santa?.name ?? tx('organizer.peek_nobody'),
                giftees: this.peeked.giftees.map((g) => g.name).join(', ') || tx('organizer.peek_nobody'),
              })}
            </span>
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-xmark"
              aria-label={tx('organizer.peek_hide')}
              onclick={() => (this.peeked = null)}
            />
          </div>
        )}
        {o.members.length > 0 && this.memberTable(e, o.members)}
        {(e.status === 'open' || e.status === 'drawn') && this.exclusionTools(e)}

        {o.reveals.length > 0 && (
          <div className="SecretSanta-log">
            <h4 className="SecretSanta-label">
              <Icon name="fas fa-eye" /> {t('organizer.reveal_log')}
            </h4>
            <ul>
              {o.reveals.map((r) => (
                <li>
                  {t('organizer.reveal_log_line', { actor: r.actor?.name ?? '?', user: r.user?.name ?? '?', time: moment(r.at) })}
                  {r.reason && <span className="SecretSanta-muted"> · {r.reason}</span>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    );
  }

  organizerState(e: Exchange): Mithril.Children {
    const o = e.organizing!;
    if (e.status === 'open') {
      if (o.drawError) return t(`organizer.draw_error_${o.drawError}`);
      return t('organizer.state_open', { time: when(e.signupEndsAt) });
    }
    if (e.status === 'drawn') return t('organizer.state_drawn', { count: o.matchCount });
    if (e.status === 'active') {
      const waiting = o.members.filter((m) => m.waiting).length;
      return [
        t('organizer.state_active', { shipped: e.shippedCount, received: e.receivedCount, count: e.participantsCount }),
        waiting ? ' ' : null,
        waiting ? t('organizer.waiting', { count: waiting }) : null,
      ];
    }
    return t('organizer.state_complete');
  }

  memberTable(e: Exchange, members: MemberRow[]) {
    const revealed = e.status === 'active' || e.status === 'complete';
    const hidden = !!e.organizing!.sentHiddenUntil;
    const flag = (value: boolean | null, yes: string, no: string) =>
      value === null ? (
        <span className="SecretSanta-muted">—</span>
      ) : value ? (
        <span className="SecretSanta-flag is-yes">
          <Icon name="fas fa-check" /> {t(yes)}
        </span>
      ) : (
        <span className="SecretSanta-flag is-no">{t(no)}</span>
      );

    return (
      <div className="SecretSanta-tableWrap">
        {hidden && <p className="helpText">{t('organizer.sent_hidden', { date: day(e.organizing!.sentHiddenUntil!) })}</p>}
        <table className="SecretSanta-table">
          <thead>
            <tr>
              <th>{t('organizer.member')}</th>
              <th>{t(`organizer.note_${e.kind}`)}</th>
              {revealed && !hidden && <th>{t('organizer.sent')}</th>}
              {revealed && <th>{t('organizer.got')}</th>}
              <th>
                <span className="sr-only">{t('organizer.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {members.map((row) => (
              <tr key={row.user.id}>
                <td>
                  <PersonLink person={row.user} />
                </td>
                <td>
                  {row.hasShippingNote ? <Icon name="fas fa-check" /> : <span className="SecretSanta-flag is-no">{t('organizer.missing')}</span>}
                </td>
                {revealed && !hidden && <td>{flag(row.shipped, 'organizer.yes', 'organizer.not_yet')}</td>}
                {revealed && (
                  <td>
                    {row.waiting ? (
                      <span className="SecretSanta-flag is-no">{t('organizer.no_santa')}</span>
                    ) : (
                      flag(row.received, 'organizer.yes', 'organizer.not_yet')
                    )}
                  </td>
                )}
                <td className="SecretSanta-rowActions">{this.memberActions(e, row)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  memberActions(e: Exchange, row: MemberRow) {
    const items: Mithril.Children[] = [];
    if (row.waiting && e.status === 'active') {
      const choice = (this.rescueFor[row.user.id] ??= Stream(''));
      const volunteers = e.participants.filter((p) => p.id !== row.user.id);
      items.push(
        <span className="SecretSanta-rescue">
          <select
            className="FormControl"
            value={choice()}
            onchange={(ev: Event) => choice((ev.target as HTMLSelectElement).value)}
            aria-label={tx('organizer.rescue_pick')}
          >
            <option value="">{tx('organizer.rescue_pick')}</option>
            {volunteers.map((p) => (
              <option value={String(p.id)}>{p.name}</option>
            ))}
          </select>
          <Button
            className="Button Button--primary"
            disabled={!choice()}
            onclick={() =>
              this.act('rescue', { user: row.user.id, volunteer: Number(choice()) }, tx('organizer.rescue_confirm'))
                .then(() => choice(''))
                .catch(() => {})
            }
          >
            {t('organizer.rescue')}
          </Button>
        </span>
      );
    }
    if (e.status !== 'open') {
      items.push(
        <Button
          className="Button Button--icon Button--flat"
          icon="fas fa-eye"
          aria-label={tx('organizer.peek', { name: row.user.name })}
          title={tx('organizer.peek', { name: row.user.name })}
          onclick={() => this.peek(e, row.user)}
        />
      );
    }
    if (e.status !== 'complete') {
      items.push(
        <Button
          className="Button Button--icon Button--flat"
          icon="fas fa-user-minus"
          aria-label={tx('organizer.dropout', { name: row.user.name })}
          title={tx('organizer.dropout', { name: row.user.name })}
          onclick={() =>
            this.act('dropout', { user: row.user.id }, tx(`organizer.dropout_confirm_${e.status}`, { name: row.user.name })).catch(() => {})
          }
        />
      );
    }
    return items;
  }

  /** The organizer reveal: a reason, then one member's Santa and giftee. Logged for every organizer to see. */
  peek(e: Exchange, person: Person) {
    app.modal.show(NoteModal, {
      heading: t('organizer.peek_title', { name: person.name }),
      intro: t('organizer.peek_intro'),
      label: t('organizer.peek_reason'),
      required: true,
      max: 200,
      submit: t('organizer.peek_submit'),
      onsubmit: (reason: string) =>
        call<{ santa: Person | null; giftees: Person[]; data: Exchange }>('POST', `/${e.id}/peek`, { user: person.id, reason }).then((res) => {
          this.set(res.data);
          this.peeked = { person, santa: res.santa, giftees: res.giftees };
        }),
    });
  }

  exclusionTools(e: Exchange) {
    const o = e.organizing!;
    const name = (id: number) => e.participants.find((p) => p.id === id)?.name ?? '?';
    return (
      <div className="SecretSanta-exclusions">
        <h4 className="SecretSanta-label">
          <Icon name="fas fa-ban" /> {t('organizer.exclusions')}
        </h4>
        <p className="helpText">{t('organizer.exclusions_help')}</p>
        {o.exclusions.length > 0 && (
          <ul className="SecretSanta-pairs">
            {o.exclusions.map(([a, b]) => (
              <li>
                {name(a)} <Icon name="fas fa-arrows-left-right" /> {name(b)}
                <Button
                  className="Button Button--icon Button--flat"
                  icon="fas fa-xmark"
                  aria-label={tx('organizer.remove_pair')}
                  onclick={() => this.act('exclude', { a, b, remove: true }).catch(() => {})}
                />
              </li>
            ))}
          </ul>
        )}
        {e.participants.length >= 2 && (
          <div className="SecretSanta-pairForm">
            {[this.pairA, this.pairB].map((pick) => (
              <select
                className="FormControl"
                value={pick()}
                onchange={(ev: Event) => pick((ev.target as HTMLSelectElement).value)}
                aria-label={tx('organizer.pick_member')}
              >
                <option value="">{tx('organizer.pick_member')}</option>
                {e.participants.map((p) => (
                  <option value={String(p.id)}>{p.name}</option>
                ))}
              </select>
            ))}
            <Button
              className="Button"
              icon="fas fa-plus"
              disabled={!this.pairA() || !this.pairB() || this.pairA() === this.pairB()}
              onclick={() =>
                this.act('exclude', { a: Number(this.pairA()), b: Number(this.pairB()) })
                  .then(() => {
                    this.pairA('');
                    this.pairB('');
                  })
                  .catch(() => {})
              }
            >
              {t('organizer.add_pair')}
            </Button>
          </div>
        )}
      </div>
    );
  }
}
