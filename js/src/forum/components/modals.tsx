import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { call, t, tx, type Exchange, type Person } from '../api';
import { Face } from './bits';

declare const m: Mithril.Static;

/** "2026-12-01T18:00" in the browser's own time, for a datetime-local field. */
function localInput(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

interface ExchangeAttrs extends IFormModalAttrs {
  exchange?: Exchange;
  onsave: (exchange: Exchange) => void;
}

/** Start an exchange, or edit one. What the draw depends on (type, deadline, review) is fixed once sign-ups close. */
export class ExchangeModal extends FormModal<ExchangeAttrs> {
  title_ = Stream('');
  budget = Stream('');
  kind = Stream<'physical' | 'digital'>('physical');
  ends = Stream('');
  shipBy = Stream('');
  description = Stream('');
  holdReveal = Stream(false);
  unmask = Stream(true);

  oninit(vnode: Mithril.Vnode<ExchangeAttrs, this>) {
    super.oninit(vnode);
    const e = this.attrs.exchange;
    const year = new Date().getFullYear();
    this.title_(e?.title ?? tx('form.default_title', { year }));
    this.budget(e?.budget ?? '');
    this.kind(e?.kind ?? 'physical');
    this.ends(localInput(e ? new Date(e.signupEndsAt) : new Date(Date.now() + 14 * 86400000)));
    this.shipBy(e?.shipBy ?? '');
    this.description(e?.description ?? '');
    this.holdReveal(e?.organizing?.holdReveal ?? false);
    this.unmask(e?.unmask ?? true);
  }

  className() {
    return 'SecretSantaModal Modal--medium';
  }

  title() {
    return t(this.attrs.exchange ? 'form.edit_title' : 'form.new_title');
  }

  content() {
    const locked = !!this.attrs.exchange && this.attrs.exchange.status !== 'open';
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="ss-title">{t('form.title')}</label>
            <input id="ss-title" className="FormControl" name="title" bidi={this.title_} maxlength={100} required />
          </div>
          <div className="SecretSantaModal-row">
            <div className="Form-group">
              <label for="ss-budget">{t('form.budget')}</label>
              <input id="ss-budget" className="FormControl" bidi={this.budget} maxlength={40} placeholder={tx('form.budget_placeholder')} />
            </div>
            <div className="Form-group">
              <label>{t('form.kind')}</label>
              <div className="SecretSantaModal-kinds" role="radiogroup">
                {(['physical', 'digital'] as const).map((kind) => (
                  <label className={'SecretSantaModal-kind' + (this.kind() === kind ? ' is-on' : '')}>
                    <input type="radio" name="kind" value={kind} checked={this.kind() === kind} disabled={locked} onchange={() => this.kind(kind)} />
                    <Icon name={kind === 'digital' ? 'fas fa-gamepad' : 'fas fa-box'} />
                    <span>{t(`kind.${kind}`)}</span>
                  </label>
                ))}
              </div>
            </div>
          </div>
          <p className="helpText">{t(`form.kind_help_${this.kind()}`)}</p>
          <div className="SecretSantaModal-row">
            <div className="Form-group">
              <label for="ss-ends">{t('form.ends')}</label>
              <input id="ss-ends" className="FormControl" type="datetime-local" bidi={this.ends} disabled={locked} required />
            </div>
            <div className="Form-group">
              <label for="ss-ship">{t('form.ship_by')}</label>
              <input
                id="ss-ship"
                className="FormControl"
                type="date"
                bidi={this.shipBy}
                // Once sign-ups close it may only move later.
                min={(locked && this.attrs.exchange?.shipBy) || this.ends().slice(0, 10)}
              />
            </div>
          </div>
          <div className="Form-group">
            <label for="ss-rules">{t('form.rules')}</label>
            <textarea
              id="ss-rules"
              className="FormControl"
              rows={5}
              bidi={this.description}
              maxlength={5000}
              placeholder={tx('form.rules_placeholder')}
            />
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.holdReveal()}
                disabled={locked}
                onchange={(e: Event) => this.holdReveal((e.target as HTMLInputElement).checked)}
              />
              {t('form.hold_reveal')}
            </label>
            <p className="helpText">{t('form.hold_reveal_help')}</p>
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.unmask()}
                disabled={locked}
                onchange={(e: Event) => this.unmask((e.target as HTMLInputElement).checked)}
              />
              {t('form.unmask')}
            </label>
            <p className="helpText">{t('form.unmask_help')}</p>
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {t(this.attrs.exchange ? 'form.save' : 'form.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const e2 = this.attrs.exchange;
    call<{ data: Exchange }>(e2 ? 'PATCH' : 'POST', e2 ? `/${e2.id}` : '', {
      title: this.title_(),
      budget: this.budget(),
      kind: this.kind(),
      signupEndsAt: new Date(this.ends()).toISOString(),
      shipBy: this.shipBy() || null,
      description: this.description(),
      holdReveal: this.holdReveal(),
      unmask: this.unmask(),
    })
      .then((res) => {
        this.hide();
        this.attrs.onsave(res.data);
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

interface EntryAttrs extends IFormModalAttrs {
  exchange: Exchange;
  onsave: (exchange: Exchange) => void;
}

/** Join, or edit your entry: wishlist, the private note only your Santa reads, and who you mustn't draw. */
export class EntryModal extends FormModal<EntryAttrs> {
  wishlist = Stream('');
  note = Stream('');
  exclusions: number[] = [];

  oninit(vnode: Mithril.Vnode<EntryAttrs, this>) {
    super.oninit(vnode);
    const me = this.attrs.exchange.me;
    this.wishlist(me?.wishlist ?? '');
    this.note(me?.shippingNote ?? '');
    this.exclusions = [...(me?.exclusions ?? [])];
  }

  className() {
    return 'SecretSantaModal Modal--medium';
  }

  title() {
    return t(this.attrs.exchange.me ? 'entry.edit_title' : 'entry.join_title', { title: this.attrs.exchange.title });
  }

  content() {
    const exchange = this.attrs.exchange;
    const kind = exchange.kind;
    const others = exchange.participants.filter((p) => p.id !== app.session.user?.id());
    const open = exchange.status === 'open';
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="ss-wishlist">{t('entry.wishlist')}</label>
            <textarea
              id="ss-wishlist"
              className="FormControl"
              name="wishlist"
              rows={6}
              bidi={this.wishlist}
              maxlength={3000}
              placeholder={tx(`entry.wishlist_placeholder_${kind}`)}
              required
            />
            <p className="helpText">{t('entry.wishlist_help')}</p>
          </div>
          <div className="Form-group">
            <label for="ss-note">
              <Icon name="fas fa-lock" /> {t(`entry.note_${kind}`)}
            </label>
            <textarea
              id="ss-note"
              className="FormControl"
              name="shippingNote"
              rows={4}
              bidi={this.note}
              maxlength={1000}
              placeholder={tx(`entry.note_placeholder_${kind}`)}
              required={kind === 'physical'}
            />
            <p className="helpText">{t('entry.note_help')}</p>
          </div>
          {open && others.length > 0 && (
            <div className="Form-group">
              <label>{t('entry.exclusions')}</label>
              <p className="helpText">{t('entry.exclusions_help', { count: 3 })}</p>
              <div className="SecretSantaModal-people">{others.map((person) => this.excludeOption(person))}</div>
            </div>
          )}
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {t(exchange.me ? 'entry.save' : 'entry.join')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  excludeOption(person: Person) {
    const on = this.exclusions.includes(person.id);
    return (
      <label className={'SecretSantaModal-person' + (on ? ' is-on' : '')}>
        <input
          type="checkbox"
          checked={on}
          disabled={!on && this.exclusions.length >= 3}
          onchange={() => (this.exclusions = on ? this.exclusions.filter((id) => id !== person.id) : [...this.exclusions, person.id])}
        />
        <Face person={person} size="small" />
        <span>{person.name}</span>
      </label>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const exchange = this.attrs.exchange;
    const body: Record<string, any> = { wishlist: this.wishlist(), shippingNote: this.note() };
    if (exchange.status === 'open') body.exclusions = this.exclusions;
    call<{ data: Exchange }>(exchange.me ? 'PATCH' : 'POST', `/${exchange.id}/entry`, body)
      .then((res) => {
        this.hide();
        if (!exchange.me) app.alerts.show({ type: 'success' }, t('entry.joined'));
        this.attrs.onsave(res.data);
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

interface NoteAttrs extends IFormModalAttrs {
  heading: Mithril.Children;
  intro?: Mithril.Children;
  label: Mithril.Children;
  placeholder?: string;
  submit: Mithril.Children;
  required?: boolean;
  max?: number;
  onsubmit: (text: string) => Promise<unknown>;
}

/** One text box and a button: shipped (with a note for your giftee), received (with thanks), and the organizer reveal's reason. */
export class NoteModal extends FormModal<NoteAttrs> {
  text = Stream('');

  className() {
    return 'SecretSantaModal Modal--small';
  }

  title() {
    return this.attrs.heading;
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          {this.attrs.intro && <p className="helpText">{this.attrs.intro}</p>}
          <div className="Form-group">
            <label for="ss-note-text">{this.attrs.label}</label>
            <textarea
              id="ss-note-text"
              className="FormControl"
              rows={4}
              bidi={this.text}
              maxlength={this.attrs.max ?? 1000}
              placeholder={this.attrs.placeholder}
              required={this.attrs.required}
            />
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {this.attrs.submit}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    this.attrs
      .onsubmit(this.text())
      .then(() => this.hide())
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
