import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { exchangeUrl, t } from './api';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link and the notifications. The pages and forms are a chunk
 * fetched when /secret-santa is opened.
 */

/** One class for every Secret Santa notification: the type and its data pick the icon and the sentence. */
class SantaNotification extends Notification {
  data(): Record<string, any> {
    return (this.attrs.notification as any).content() || {};
  }

  type(): string {
    return (this.attrs.notification as any).contentType();
  }

  icon() {
    const data = this.data();
    switch (this.type()) {
      case 'secretSantaMatched':
        return 'fas fa-gift';
      case 'secretSantaShipped':
        return 'fas fa-truck-fast';
      case 'secretSantaReceived':
        return 'fas fa-box-open';
      case 'secretSantaMessage':
        return 'fas fa-envelope';
      case 'secretSantaJoined':
        return 'fas fa-user-plus';
      default:
        return data.news === 'draw_failed' ? 'fas fa-triangle-exclamation' : 'fas fa-snowflake';
    }
  }

  href() {
    return exchangeUrl(this.data().exchange);
  }

  content(): Mithril.Children {
    const data = this.data();
    // 🚨 A missing to-one relationship is `false`, not null: the alerts a giftee gets have no sender.
    const from = (this.attrs.notification as any).fromUser();
    const username = from ? from.displayName() : '';
    switch (this.type()) {
      case 'secretSantaMatched':
        return t(`notification.matched_${data.change || 'draw'}`, { username });
      case 'secretSantaShipped':
        return t('notification.shipped');
      case 'secretSantaReceived':
        return t('notification.received', { username });
      case 'secretSantaMessage':
        return data.fromSanta ? t('notification.message_from_santa') : t('notification.message_from_giftee', { username });
      case 'secretSantaJoined':
        return t('notification.joined', { username });
      default:
        return t(`notification.news_${data.news}`);
    }
  }

  excerpt(): Mithril.Children {
    return this.data().title;
  }
}

export const TYPES: [string, string][] = [
  ['secretSantaMatched', 'fas fa-gift'],
  ['secretSantaShipped', 'fas fa-truck-fast'],
  ['secretSantaReceived', 'fas fa-box-open'],
  ['secretSantaMessage', 'fas fa-envelope'],
  ['secretSantaJoined', 'fas fa-user-plus'],
  ['secretSantaNews', 'fas fa-snowflake'],
];

app.initializers.add('ernestdefoe/secret-santa', () => {
  const page = () => import('./components/pages').then(async (pages) => (await pages.loadCore(), pages));
  app.routes['secret-santa'] = { path: '/secret-santa', component: () => page().then((p) => ({ default: p.ListPage })) };
  app.routes['secret-santa.exchange'] = { path: '/secret-santa/:id', component: () => page().then((p) => ({ default: p.ExchangePage })) };

  for (const [type] of TYPES) app.notificationComponents[type] = SantaNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('secretSantaCanView') || !app.forum.attribute('secretSantaNav')) return;
    items.add('secret-santa', LinkButton.component({ href: app.route('secret-santa'), icon: 'fas fa-gift' }, t('nav')), 79);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('secretSantaCanView')) return;
    for (const [type, icon] of TYPES) items.add(type, { name: type, icon, label: t(`notification.preference.${type}`) });
  });
});
