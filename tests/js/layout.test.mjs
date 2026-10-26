// Run with `npm run test:js`. Structural rules of the shared layout: the brand comes from ONE shared source, the sidebar can collapse without losing its labels,
// roles are text (not capsules) and the date fields explain their bounds.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const source = (path) => readFileSync(fileURLToPath(new URL(`../../resources/js/${path}`, import.meta.url)), 'utf8');

test('the visible brand comes from the shared configuration, never from text typed in a layout', () => {
    for (const file of ['Components/Topbar.jsx', 'Components/Sidebar.jsx', 'Layouts/AccessLayout.jsx', 'Layouts/SignupLayout.jsx', 'Layouts/GuestLayout.jsx']) {
        const code = source(file);
        assert.match(code, /useBrand(Short)?\(\)/, `${file} reads the brand`);
        assert.doesNotMatch(code, />\s*(CCG Avachat|CCG Platform)\s*</, `${file} has no hard-coded brand`);
    }
    assert.match(source('Hooks/useBrand.js'), /props\.brand/);
    assert.match(source('bootstrap.jsx'), /meta\[name="brand"\]/, 'the page title uses the server brand too');
});

test('a long brand cannot break the top bar or the sidebar header', () => {
    assert.match(source('Components/Topbar.jsx'), /truncate/);
    assert.match(source('Components/Sidebar.jsx'), /line-clamp-2/);
    assert.match(source('Layouts/GuestLayout.jsx'), /break-words/);
});

test('the sidebar collapses to icons on large screens and every label stays available to screen readers', () => {
    const sidebar = source('Components/Sidebar.jsx');
    assert.match(sidebar, /data-collapsed=/);
    assert.match(sidebar, /aria-expanded=\{!collapsed\}/);
    assert.match(sidebar, /<Menu className/, 'the toggle is only the three-line icon, in the header');
    assert.match(sidebar, /<Tooltip content=/, 'its name is a tooltip, not permanent text');
    assert.doesNotMatch(sidebar, /Contraer menú<\/span>/, 'no permanent text beside the icon');
    assert.match(sidebar, /lg:data-\[collapsed=true\]:w-16/);
    assert.match(sidebar, /lg:w-64 shrink-0/, 'it never squeezes');
    const item = source('Components/NavItem.jsx');
    assert.match(item, /lg:group-data-\[collapsed=true\]\/side:sr-only/, 'collapsed: the label is read, not removed');
    assert.match(item, /title=\{item\.label\}/, 'and shown as a tooltip');
    assert.match(source('Layouts/AppLayout.jsx'), /useSidebarCollapsed\(\)/);
    assert.match(source('Hooks/useSidebarCollapsed.js'), /try \{[\s\S]*localStorage[\s\S]*catch/, 'the stored choice is optional');
});

test('roles are shown as text with a semantic color, not as capsules', () => {
    const label = source('Components/RoleLabel.jsx');
    assert.doesNotMatch(label, /rounded-full/);
    assert.match(label, /capitalize/);
    assert.match(label, /break-words/);
    for (const file of ['Pages/Users/Partials/UsersPanel.jsx', 'Pages/Users/Partials/RolesPanel.jsx', 'Pages/Users/Partials/WorkspacesPanel.jsx', 'Pages/Users/Partials/PermissionsPanel.jsx', 'Pages/Users/Partials/GrantsModal.jsx', 'Components/Topbar.jsx', 'Pages/Profile/Partials/ProfileHero.jsx', 'Pages/Reports/Partials/TeamPanel.jsx']) {
        assert.match(source(file), /<RoleLabel /, `${file} uses RoleLabel`);
        assert.doesNotMatch(source(file), /<Badge tone=\{roleTone/, `${file} no longer draws a role as a capsule`);
    }
});

test('the date fields of the filters share one component with min, max and an error message', () => {
    const field = source('Components/DateField.jsx');
    assert.match(field, /dateProblem/);
    assert.match(field, /aria-describedby/);
    for (const file of ['Pages/Bookings/Partials/AgendaPanel.jsx', 'Pages/Audit/Partials/AuditFilters.jsx']) assert.match(source(file), /<DateField /, `${file} uses DateField`);
});

test('the report filter by location is sent with every change of the period', () => {
    const reports = source('Pages/Reports/Index.jsx');
    assert.match(reports, /location: locationParam/);
    assert.match(reports, /places\.length > 1/, 'a selector only when there is something to choose');
});

test('Tabs ignores the panel of a hidden tab, so a page with a conditional panel (Configuraciones for a client Workspace) never renders blank', () => {
    const tabs = readFileSync(new URL('../../resources/js/Components/Tabs.jsx', import.meta.url), 'utf8');

    assert.match(tabs, /Children\.toArray\(children\)/);
    assert.doesNotMatch(tabs, /Array\.isArray\(children\)/);
});
