// Run with `npm run test:js` (Node's built-in runner: no new dependency). Covers the menu definition and the sidebar's layout rules.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { isCurrentRoute, isFolded, navigation, openAfterNavigation, openAfterToggle, sectionHasRoute, startsOpen, visibleNavigation } from '../../resources/js/config/navigation.js';

const source = (path) => readFileSync(fileURLToPath(new URL(`../../resources/js/${path}`, import.meta.url)), 'utf8');
const everything = { permissions: navigation.flatMap((s) => s.items).map((i) => i.permission).filter(Boolean), modules: navigation.flatMap((s) => s.items).map((i) => i.module).filter(Boolean), platform: true };

test('the menu has three groups by function and every one of them can be folded', () => {
    assert.deepEqual(navigation.map((section) => section.label), ['General', 'Operación', 'Administración']);
    navigation.forEach((section) => assert.ok(section.label, 'no section is left without a heading, so none is fixed outside a group'));
    navigation.forEach((section) => assert.equal('collapsible' in section, false, 'foldability is not an opt-in flag'));
    assert.equal(startsOpen(navigation[0], false), false, 'General is a group like the others: it folds even when it is the first one');

    const component = source('Components/NavSection.jsx');
    assert.match(component, /foldable = Boolean\(section\.label\)/);
    assert.doesNotMatch(component, /hasActive\)\s*$/m, 'the section of the current page must stay foldable');
    assert.doesNotMatch(component, /!open && !hasActive/);
});

test('General, Operación and Administración hold exactly the agreed modules, each once', () => {
    const labels = (name) => navigation.find((section) => section.label === name).items.map((item) => item.label);

    assert.deepEqual(labels('General'), ['Dashboard', 'Asistentes', 'Conversaciones']);
    assert.deepEqual(labels('Operación'), ['Reportes', 'Catálogo', 'Pedidos', 'Servicios', 'Sedes', 'Profesionales', 'Agenda', 'Clientes', 'Campañas']);
    assert.deepEqual(labels('Administración'), ['Usuarios y Roles', 'Auditoría', 'Configuraciones', 'Integraciones', 'Planes']);

    const modules = navigation.flatMap((section) => section.items).map((item) => item.module).filter(Boolean);
    assert.equal(new Set(modules).size, modules.length, 'a reusable module is listed once, not once per solution');
    // Calendar providers are configured inside Integraciones: they have no menu entry of their own.
    assert.equal(modules.includes('calendar_providers'), false);
});

test('every menu module is an implemented one of the catalog and every implemented page is in the menu', () => {
    const catalog = source('../../config/modules.php');
    const implemented = [...catalog.slice(catalog.indexOf("'implemented' => [")).matchAll(/^\s{8}'([a-z_]+)' => \['name'/gm)].map((match) => match[1]);
    const menu = navigation.flatMap((section) => section.items).map((item) => item.module).filter(Boolean);

    assert.deepEqual(menu.filter((key) => !implemented.includes(key)), [], 'the menu never lists a module the catalog does not implement');
    assert.deepEqual(implemented.filter((key) => !menu.includes(key) && key !== 'calendar_providers'), [], 'an implemented module with its own page must be reachable');
});

test('headings and routes are unique', () => {
    const labels = navigation.map((s) => s.label).filter(Boolean);
    const routes = navigation.flatMap((s) => s.items.map((i) => i.route));

    assert.equal(new Set(labels).size, labels.length);
    assert.equal(new Set(routes).size, routes.length);
});

test('the heading never wraps and the sidebar keeps its width', () => {
    const section = source('Components/NavSection.jsx');
    const sidebar = source('Components/Sidebar.jsx');

    assert.match(section, /truncate/, 'long titles are cut, not wrapped');
    assert.match(section, /h-8/, 'a heading has a fixed height');
    assert.match(section, /ChevronDown className=\{`size-3\.5 shrink-0/, 'the chevron keeps its size');
    assert.match(section, /aria-expanded=\{open\}/);
    assert.match(sidebar, /lg:w-64 shrink-0/, 'the flex row can not squeeze the sidebar when the content is wide');
    assert.match(sidebar, /sidebar-scroll/, 'the menu uses the slim overlay scrollbar, which reserves no gutter and shifts nothing while unfolding');
    assert.doesNotMatch(sidebar, /scrollbar-gutter/, 'no space is reserved for the scrollbar');
    assert.match(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'), /\.sidebar-scroll::-webkit-scrollbar\s*\{\s*width: 5px/, 'a slim 5px scrollbar');
    assert.doesNotMatch(section + sidebar, /w-\[\d+px\]|min-w-\[\d+px\]/, 'no fixed pixel widths');
});

test('a workspace only sees what its plan includes and its role allows', () => {
    const names = (access) => visibleNavigation(access).flatMap((s) => s.items.map((i) => i.label));

    assert.deepEqual(names({ permissions: ['view-dashboard'], modules: ['dashboard'], platform: false }), ['Dashboard']);
    assert.deepEqual(names({ permissions: [], modules: [], platform: false }), []);
    assert.ok(names(everything).includes('Planes'));
    assert.equal(names({ ...everything, platform: false }).includes('Planes'), false, 'Planes is only for the platform administration');

    // A module the plan includes is still hidden without the permission, and a permission without the module shows nothing.
    assert.equal(names({ permissions: [], modules: everything.modules, platform: false }).includes('Clientes'), false);
    assert.equal(names({ permissions: everything.permissions, modules: ['dashboard'], platform: false }).includes('Clientes'), false);
    assert.ok(names({ permissions: ['view-customers'], modules: ['customers'], platform: false }).includes('Clientes'));

    // Sections without visible items disappear instead of leaving an empty heading.
    const sections = visibleNavigation({ permissions: ['view-customers'], modules: ['customers'], platform: false });
    assert.deepEqual(sections.map((s) => s.label), ['Operación']);
});

test('the module catalog is not turned into permanent menu entries: only implemented modules appear, once', () => {
    const modules = navigation.flatMap((s) => s.items).map((i) => i.module).filter(Boolean);

    assert.equal(new Set(modules).size, modules.length);
});

test('the current page keeps its section and item active on the pages of a module', () => {
    // Ziggy's route().current(pattern) accepts the "*" wildcard: this stands in for it.
    const page = (name) => (pattern) => new RegExp('^' + pattern.split('.').join('\.').split('*').join('.*') + '$').test(name);
    const customers = navigation.find((s) => s.label === 'Operación');

    assert.equal(isCurrentRoute('customers.index', page('customers.show')), true);
    assert.equal(isCurrentRoute('customers.index', page('customers.index')), true);
    assert.equal(isCurrentRoute('dashboard', page('customers.show')), false);
    assert.equal(isCurrentRoute('customers.index', page('campaigns.show')), false);
    assert.equal(sectionHasRoute(customers, page('campaigns.show')), true);
    assert.equal(sectionHasRoute(customers, page('dashboard')), false);
});

test('every group folds and unfolds, also the one that holds the current page', () => {
    for (const section of navigation) {
        // A group starts folded unless it holds the current page...
        assert.equal(startsOpen(section, false), false, section.label);
        assert.equal(startsOpen(section, true), true, section.label);

        // ...and the user can fold the group of the current page and unfold it again.
        let open = startsOpen(section, true);
        open = openAfterToggle(open);
        assert.equal(isFolded(section, open), true, section.label);
        open = openAfterToggle(open);
        assert.equal(isFolded(section, open), false, section.label);
    }
});

test('groups fold independently: folding one never changes another', () => {
    const states = Object.fromEntries(navigation.map((section) => [section.label, startsOpen(section, section.label === 'Operación')]));
    states.General = openAfterToggle(states.General);

    assert.equal(states.General, true);
    assert.equal(states.Operación, true, 'the active group stays as it was');
    assert.equal(states.Administración, false);
});

test('navigation unfolds the section it arrives at but never folds the one the user left open', () => {
    assert.equal(openAfterNavigation(false, true), true, 'arriving at one of its pages unfolds it');
    assert.equal(openAfterNavigation(true, false), true, 'leaving it keeps it as the user left it');
    assert.equal(openAfterNavigation(false, false), false, 'an unrelated page does not unfold it');
    // A section the user folded while on its page stays folded until navigation changes the page.
    assert.equal(openAfterNavigation(openAfterToggle(true), false), false);
});

// --- what the menu and the quick links of the Dashboard offer depends on the plan AND on the role, never on a plan name ---------------------------------

const labelsOf = (access) => visibleNavigation(access).flatMap((section) => section.items).map((item) => item.label);
const allPermissions = navigation.flatMap((s) => s.items).map((i) => i.permission).filter(Boolean);

test('a plan without Sedes or Campañas does not offer them, whatever the role holds', () => {
    const health = ['dashboard', 'assistants', 'conversations', 'reports', 'service_catalog', 'professionals', 'bookings', 'customers', 'users_roles', 'settings'];
    const offered = labelsOf({ permissions: allPermissions, modules: health, platform: false });

    assert.ok(!offered.includes('Sedes') && !offered.includes('Campañas') && !offered.includes('Pedidos') && !offered.includes('Catálogo'));
    ['Servicios', 'Profesionales', 'Agenda', 'Clientes'].forEach((label) => assert.ok(offered.includes(label), `${label} is in the plan`));
});

test('a module in the plan is not offered to a role without its permission, and permissions are independent per resource', () => {
    const modules = navigation.flatMap((s) => s.items).map((i) => i.module).filter(Boolean);

    assert.deepEqual(labelsOf({ permissions: [], modules, platform: false }), []);
    // Seeing the list of services does not open professionals, locations or the agenda.
    assert.deepEqual(labelsOf({ permissions: ['view-services'], modules, platform: false }), ['Servicios']);
    assert.deepEqual(labelsOf({ permissions: ['view-locations', 'view-professionals'], modules, platform: false }), ['Sedes', 'Profesionales']);
    // Operation permissions never open a page by themselves.
    assert.deepEqual(labelsOf({ permissions: ['create-customers', 'edit-customers', 'create-bookings'], modules, platform: false }), []);
});

test('the quick links are drawn from the same definition as the menu', () => {
    const panels = source('Pages/Dashboard/Partials/Panels.jsx');

    assert.match(panels, /visibleNavigation\(useAccess\(\)\)/);
    assert.doesNotMatch(panels, /Sedes|Campañas|health|salud/i, 'no plan or module name is written by hand in the quick links');
});

test('the platform entries only exist for the platform administration', () => {
    const modules = navigation.flatMap((s) => s.items).map((i) => i.module).filter(Boolean);

    assert.ok(!labelsOf({ permissions: allPermissions, modules, platform: false }).includes('Planes'));
    assert.ok(labelsOf({ permissions: allPermissions, modules, platform: true }).includes('Planes'));
});
