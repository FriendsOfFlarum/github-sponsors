import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Link from 'flarum/common/components/Link';

export default class ExtensionSettingsPage extends ExtensionPage {
    oninit(vnode) {
        super.oninit(vnode);
    }

    getOptions() {
        return ['user', 'organization'].reduce((o, type) => {
            o[type] = app.translator.trans(`fof-github-sponsors.admin.account_types.${type}`);

            return o;
        }, {});
    }

    content() {
        return [
            <div className="container">
                <div className="GithubSponsorsSettings">
                    <div className="Form">
                        {this.buildSettingComponent({
                            type: 'password',
                            setting: 'fof-github-sponsors.api_token',
                            label: app.translator.trans('fof-github-sponsors.admin.settings.api_token_label'),
                            help: app.translator.trans('fof-github-sponsors.admin.settings.desc', {
                                a: <Link href="https://github.com/settings/tokens" target="_blank" />,
                            }),
                        })}

                        {this.buildSettingComponent({
                            type: 'select',
                            setting: 'fof-github-sponsors.account_type',
                            label: app.translator.trans('fof-github-sponsors.admin.settings.account_type_label'),
                            options: this.getOptions(),
                            required: true,
                        })}

                        {this.buildSettingComponent({
                            type: 'string',
                            setting: 'fof-github-sponsors.login',
                            label: app.translator.trans('fof-github-sponsors.admin.settings.login_label'),
                            required: true,
                        })}

                        {this.buildSettingComponent({
                            type: 'select',
                            setting: 'fof-github-sponsors.group_id',
                            label: app.translator.trans('fof-github-sponsors.admin.settings.group_label'),
                            options: app.store.all('groups').reduce((o, g) => {
                                o[g.id()] = g.nameSingular();

                                return o;
                            }, {}),
                            required: true,
                        })}
                        {this.submitButton()}
                    </div>
                </div>
            </div>,
        ];
    }
}
