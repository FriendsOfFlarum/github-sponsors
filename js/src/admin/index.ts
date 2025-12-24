import app from 'flarum/admin/app';
import ExtensionSettingsPage from './components/ExtensionSettingsPage';

app.initializers.add('fof/github-sponsors', () => {
  app.extensionData.for('fof-github-sponsors').registerPage(ExtensionSettingsPage);
});
