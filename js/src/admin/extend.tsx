import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import Group from 'flarum/common/models/Group';
import Link from 'flarum/common/components/Link';

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'fof-github-sponsors.api_token',
      type: 'password',
      label: app.translator.trans('fof-github-sponsors.admin.settings.api_token_label'),
      help: app.translator.trans('fof-github-sponsors.admin.settings.api_token_help', {
        a: <Link href="https://github.com/settings/tokens/new" target="_blank" />,
      }),
    }))
    .setting(() => ({
      setting: 'fof-github-sponsors.account_type',
      type: 'select',
      label: app.translator.trans('fof-github-sponsors.admin.settings.account_type_label'),
      help: app.translator.trans('fof-github-sponsors.admin.settings.account_type_help'),
      options: ['user', 'organization'].reduce<Record<string, any>>((o, type) => {
        o[type] = app.translator.trans(`fof-github-sponsors.admin.account_types.${type}`);
        return o;
      }, {}),
      required: true,
    }))
    .setting(() => ({
      setting: 'fof-github-sponsors.login',
      type: 'string',
      label: app.translator.trans('fof-github-sponsors.admin.settings.login_label'),
      help: app.translator.trans('fof-github-sponsors.admin.settings.login_help'),
      required: true,
    }))
    .setting(() => ({
      setting: 'fof-github-sponsors.group_id',
      type: 'select',
      label: app.translator.trans('fof-github-sponsors.admin.settings.group_label'),
      help: app.translator.trans('fof-github-sponsors.admin.settings.group_help'),
      options: app.store.all<Group>('groups').reduce<Record<string, any>>((o, g) => {
        const id = g.id();
        if (id) {
          o[id] = g.nameSingular();
        }
        return o;
      }, {}),
      required: true,
    })),
];
