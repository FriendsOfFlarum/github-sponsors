import { settings } from '@fof-components';
const {
    SettingsModal,
    items: { StringItem, SelectItem },
} = settings;

const getOptions = () =>
    ['user', 'organization'].reduce((o, type) => {
        o[type] = app.translator.trans(`fof-github-sponsors.admin.account_types.${type}`);

        return o;
    }, {});

app.initializers.add('fof/github-sponsors', () => {
    app.extensionSettings['fof-github-sponsors'] = () =>
        app.modal.show(
            new SettingsModal({
                title: app.translator.trans('fof-github-sponsors.admin.settings.title'),
                size: 'medium',
                items: [
                    <p>
                        {app.translator.trans('fof-github-sponsors.admin.settings.desc', {
                            a: <a href="https://github.com/settings/tokens" target="_blank" />,
                        })}
                    </p>,
                    <StringItem key="fof-github-sponsors.api_token" required type="password">
                        {app.translator.trans('fof-github-sponsors.admin.settings.api_token_label')}
                    </StringItem>,
                    [
                        <label>{app.translator.trans('fof-github-sponsors.admin.settings.account_type_label')}</label>,
                        <SelectItem key="fof-github-sponsors.account_type" options={getOptions()} required />,
                    ],
                    <StringItem key="fof-github-sponsors.login" required>
                        {app.translator.trans('fof-github-sponsors.admin.settings.login_label')}
                    </StringItem>,
                    <div className="Form-group">
                        <label>{app.translator.trans('fof-github-sponsors.admin.settings.group_label')}</label>

                        {SelectItem.component({
                            options: app.store.all('groups').reduce((o, g) => {
                                o[g.id()] = g.nameSingular();

                                return o;
                            }, {}),
                            key: 'fof-github-sponsors.group_id',
                            required: true,
                        })}
                    </div>,
                ],
            })
        );
});
