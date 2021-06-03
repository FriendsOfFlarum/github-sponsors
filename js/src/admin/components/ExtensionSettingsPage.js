import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import { settings } from '@fof-components';
import Link from 'flarum/common/components/Link';

const {
    items: { StringItem, SelectItem },
} = settings;

export default class ExtensionSettingsPage extends ExtensionPage {
    oninit(vnode) {
        super.oninit(vnode);

        this.setting = this.setting.bind(this);
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
                    <p>
                        {app.translator.trans('fof-github-sponsors.admin.settings.desc', {
                            a: <Link href="https://github.com/settings/tokens" target="_blank" />,
                        })}
                    </p>
                    <StringItem setting={this.setting} name="fof-github-sponsors.api_token" required type="password">
                        {app.translator.trans('fof-github-sponsors.admin.settings.api_token_label')}
                    </StringItem>
                    <label>{app.translator.trans('fof-github-sponsors.admin.settings.account_type_label')}</label>
                    <SelectItem setting={this.setting} name="fof-github-sponsors.account_type" options={this.getOptions()} required />
                    <StringItem setting={this.setting} name="fof-github-sponsors.login" required>
                        {app.translator.trans('fof-github-sponsors.admin.settings.login_label')}
                    </StringItem>
                    <div className="Form-group">
                        <label>{app.translator.trans('fof-github-sponsors.admin.settings.group_label')}</label>

                        {SelectItem.component({
                            options: app.store.all('groups').reduce((o, g) => {
                                o[g.id()] = g.nameSingular();

                                return o;
                            }, {}),
                            name: 'fof-github-sponsors.group_id',
                            setting: this.setting,
                            required: true,
                        })}
                    </div>
                    {this.submitButton()}
                </div>
            </div>,
        ];
    }
}
