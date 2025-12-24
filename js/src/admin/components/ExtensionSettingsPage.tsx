import Form from 'flarum/common/components/Form';
import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Link from 'flarum/common/components/Link';
import Group from 'flarum/common/models/Group';
import type Mithril from 'mithril';

export default class ExtensionSettingsPage extends ExtensionPage {
  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
  }

  getOptions() {
    return ['user', 'organization'].reduce<Record<string, any>>((o, type) => {
      o[type] = app.translator.trans(`fof-github-sponsors.admin.account_types.${type}`);

      return o;
    }, {});
  }

  content() {
    return (
      <div className="container">
        <div className="GithubSponsorsSettings">
          <Form>
            {this.buildSettingComponent({
              type: 'password',
              setting: 'fof-github-sponsors.api_token',
              label: app.translator.trans('fof-github-sponsors.admin.settings.api_token_label'),

              help: app.translator.trans('fof-github-sponsors.admin.settings.api_token_help', {
                a: <Link href="https://github.com/settings/tokens/new" target="_blank" />,
              }),
            })}
            {this.buildSettingComponent({
              type: 'select',
              setting: 'fof-github-sponsors.account_type',
              label: app.translator.trans('fof-github-sponsors.admin.settings.account_type_label'),
              help: app.translator.trans('fof-github-sponsors.admin.settings.account_type_help'),
              options: this.getOptions(),
              required: true,
            })}
            {this.buildSettingComponent({
              type: 'string',
              setting: 'fof-github-sponsors.login',
              label: app.translator.trans('fof-github-sponsors.admin.settings.login_label'),
              help: app.translator.trans('fof-github-sponsors.admin.settings.login_help'),
              required: true,
            })}
            {this.buildSettingComponent({
              type: 'select',
              setting: 'fof-github-sponsors.group_id',
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
            })}
            {this.submitButton()}
          </Form>
        </div>
      </div>
    );
  }
}
