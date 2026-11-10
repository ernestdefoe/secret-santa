import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface Person {
  id: number;
  name: string;
  slug: string;
  avatar: string | null;
}

export type Status = 'open' | 'drawn' | 'active' | 'complete';
export type Kind = 'physical' | 'digital';

export interface Card {
  id: number;
  title: string;
  budget: string | null;
  kind: Kind;
  status: Status;
  signupEndsAt: string;
  shipBy: string | null;
  participantsCount: number;
  shippedCount: number;
  receivedCount: number;
  organizer: Person | null;
  joined: boolean;
  faces: Person[];
}

export interface Note {
  mine: boolean;
  body: string;
  at: string;
}

export interface Giving {
  user: Person | null;
  wishlist: string | null;
  shippingNote: string | null;
  shippedAt: string | null;
  giftNote: string | null;
  receivedAt: string | null;
  thanks: string | null;
  messages: Note[];
}

export interface Receiving {
  waiting?: boolean;
  shippedAt?: string | null;
  giftNote?: string | null;
  receivedAt?: string | null;
  thanks?: string | null;
  messages?: Note[];
  santa?: Person | null;
}

export interface MemberRow {
  user: Person;
  joinedAt: string | null;
  hasShippingNote: boolean;
  shipped: boolean | null;
  received: boolean | null;
  waiting: boolean;
}

export interface Organizing {
  holdReveal: boolean;
  drawError: 'too_few' | 'exclusions' | 'busy' | null;
  matchCount: number;
  sentHiddenUntil: string | null;
  members: MemberRow[];
  exclusions: [number, number][];
  reveals: { actor: Person | null; user: Person | null; reason: string | null; at: string }[];
}

export interface Exchange {
  id: number;
  title: string;
  description: string | null;
  budget: string | null;
  kind: Kind;
  status: Status;
  signupEndsAt: string;
  shipBy: string | null;
  unmask: boolean;
  participantsCount: number;
  shippedCount: number;
  receivedCount: number;
  drawnAt: string | null;
  revealedAt: string | null;
  completedAt: string | null;
  organizer: Person | null;
  participants: Person[];
  discussion: { id: number; slug: string; title: string } | null;
  can: { join: boolean; leave: boolean; organize: boolean; delete: boolean; startThread: boolean };
  me: { wishlist: string; shippingNote: string | null; exclusions: number[] } | null;
  giving: Giving[];
  receiving: Receiving | null;
  organizing: Organizing | null;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-secret-santa.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-secret-santa.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return extractText(app.translator.trans('core.lib.error.generic_message'));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any>): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/secret-santa' + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + new URLSearchParams(data).toString() : url,
    body: method === 'GET' ? undefined : data,
    errorHandler: (error: any) => {
      app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

export function exchangeUrl(id: number): string {
  return app.route('secret-santa.exchange', { id });
}
