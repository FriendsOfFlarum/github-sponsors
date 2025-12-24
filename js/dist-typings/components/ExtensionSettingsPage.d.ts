import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export default class ExtensionSettingsPage extends ExtensionPage {
    oninit(vnode: Mithril.Vnode): void;
    getOptions(): Record<string, any>;
    content(): JSX.Element;
}
