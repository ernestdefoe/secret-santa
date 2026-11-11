import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import stringToColor from 'flarum/common/utils/stringToColor';
import humanTime from 'flarum/common/utils/humanTime';
import type Mithril from 'mithril';
import { t, tx, type Kind, type Person, type Status } from '../api';

/** Small pieces both pages use. People are plain objects from our endpoints, not store models. */

export function Face(_: { attrs: { person: Person; size?: 'small' | 'large'; title?: boolean } }) {
  return {
    view: ({ attrs }: { attrs: { person: Person; size?: 'small' | 'large'; title?: boolean } }) => {
      const { person } = attrs;
      const className = 'Avatar SecretSanta-face' + (attrs.size ? ' SecretSanta-face--' + attrs.size : '');
      return person.avatar ? (
        <img className={className} src={person.avatar} alt={person.name} title={person.name} loading={attrs.size === 'large' ? 'eager' : 'lazy'} />
      ) : (
        <span
          className={className}
          style={{ '--avatar-bg': '#' + stringToColor(person.name) }}
          role="img"
          aria-label={person.name}
          title={person.name}
        >
          {person.name.charAt(0).toUpperCase()}
        </span>
      );
    },
  };
}

export function PersonLink(_: { attrs: { person: Person | null } }) {
  return {
    view: ({ attrs }: { attrs: { person: Person | null } }) =>
      attrs.person ? (
        <Link className="SecretSanta-person" href={app.route('user', { username: attrs.person.slug })}>
          <Face person={attrs.person} />
          <span className="SecretSanta-personName">{attrs.person.name}</span>
        </Link>
      ) : (
        <span className="SecretSanta-person">{app.translator.trans('core.lib.username.deleted_text')}</span>
      ),
  };
}

export function Faces(_: { attrs: { people: Person[]; total: number } }) {
  return {
    view: ({ attrs }: { attrs: { people: Person[]; total: number } }) => {
      const more = attrs.total - attrs.people.length;
      return (
        <span className="SecretSanta-faces" aria-label={tx('card.members', { count: attrs.total })}>
          {attrs.people.map((person) => (
            <Face person={person} size="small" />
          ))}
          {more > 0 && <span className="SecretSanta-facesMore">+{more}</span>}
        </span>
      );
    },
  };
}

const STATUS_ICONS: Record<Status, string> = {
  open: 'fas fa-pen-to-square',
  drawn: 'fas fa-shuffle',
  active: 'fas fa-truck-fast',
  complete: 'fas fa-circle-check',
};

export function StatusPill(_: { attrs: { status: Status } }) {
  return {
    view: ({ attrs }: { attrs: { status: Status } }) => (
      <span className={'SecretSanta-status SecretSanta-status--' + attrs.status}>
        <Icon name={STATUS_ICONS[attrs.status]} /> {t(`status.${attrs.status}`)}
      </span>
    ),
  };
}

export function kindIcon(kind: Kind): string {
  return kind === 'digital' ? 'fas fa-gamepad' : 'fas fa-box';
}

/** "in 3 days" / "3 days ago". Core's humanTime turns every future time into "just now", and deadlines are in the future. */
export function when(iso: string): string {
  return dayjs(iso).isAfter(dayjs()) ? dayjs(iso).fromNow() : humanTime(iso);
}

export function day(date: string): string {
  return dayjs(date).format('ll');
}

export function moment(iso: string): string {
  return dayjs(iso).format('lll');
}

type TextAttrs = { text: string | null | undefined; className?: string };

/** Plain text with line breaks kept; never HTML. */
export function Text(_: { attrs: TextAttrs }) {
  return {
    view: ({ attrs }: { attrs: TextAttrs }) =>
      attrs.text ? <div className={'SecretSanta-text ' + (attrs.className || '')}>{attrs.text}</div> : null,
  };
}

declare const dayjs: any;
export type Children = Mithril.Children;
