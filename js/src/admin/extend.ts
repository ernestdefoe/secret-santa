import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-secret-santa.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-secret-santa.${key}`;

/** Tags the "show what you got" discussion can go in, when flarum/tags is on. */
function tagOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.thread_tag_none')) };
  for (const tag of app.store.all<any>('tags')) options[tag.id()] = tag.name();
  return options;
}

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(
      () =>
        'flarum-tags' in ((globalThis as any).flarum?.extensions || {})
          ? {
              setting: setting('thread_tag'),
              type: 'select',
              options: tagOptions(),
              default: '',
              label: t('settings.thread_tag'),
              help: t('settings.thread_tag_help'),
            }
          : null,
      90
    )
    .permission(() => ({ permission: 'secret-santa.view', icon: 'fas fa-gift', label: t('permissions.view'), allowGuest: true }), 'view', 55)
    .permission(() => ({ permission: 'secret-santa.join', icon: 'fas fa-gift', label: t('permissions.join') }), 'reply', 55)
    .permission(() => ({ permission: 'secret-santa.start', icon: 'fas fa-gifts', label: t('permissions.start') }), 'start', 55)
    .permission(
      () => ({ permission: 'secret-santa.moderate', icon: 'fas fa-wand-magic-sparkles', label: t('permissions.moderate') }),
      'moderate',
      55
    ),
];
