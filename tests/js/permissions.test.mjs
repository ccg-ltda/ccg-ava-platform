import assert from 'node:assert/strict';
import test from 'node:test';
import { groupPermissions, matchesSearch, missingRequirements, normalize, permissionLabel, selectionDiff, summarizeRole } from '../../resources/js/lib/permissions.js';

const catalog = {
    groups: {
        users: { label: 'Usuarios y roles', list: 'manage-users' },
        platform: { label: 'Administración de la plataforma' },
        empty: { label: 'Vacío' },
    },
    items: [
        { name: 'manage-users', label: 'Acceder a Usuarios y roles', description: 'Abre el módulo.', group: 'users', requires: null },
        { name: 'create-users', label: 'Crear usuarios', group: 'users', requires: 'manage-users' },
        { name: 'assign-roles', label: 'Asignar roles y membresías', group: 'users', requires: 'manage-users' },
        { name: 'manage-plans', label: 'Administrar planes comerciales', group: 'platform', platform: true, requires: null },
    ],
};

test('permissions are grouped by area, in the order of the groups, without empty areas', () => {
    const groups = groupPermissions(catalog);

    assert.deepEqual(groups.map((group) => group.id), ['users', 'platform']);
    assert.deepEqual(groups[0].items.map((item) => item.name), ['manage-users', 'create-users', 'assign-roles']);
    assert.equal(groups[0].label, 'Usuarios y roles');
});

test('a role only shows the areas of the permissions it holds', () => {
    const groups = groupPermissions(catalog, ['assign-roles']);

    assert.deepEqual(groups.map((group) => group.id), ['users']);
    assert.deepEqual(groups[0].items.map((item) => item.name), ['assign-roles']);
});

test('the label comes from the catalog and falls back to the permission name', () => {
    assert.equal(permissionLabel(catalog, 'create-users'), 'Crear usuarios');
    assert.equal(permissionLabel(catalog, 'unknown-permission'), 'unknown-permission');
});

test('a role is summarised by resource with how many of its permissions it holds', () => {
    assert.deepEqual(summarizeRole(catalog, ['create-users', 'assign-roles']).map(({ id, granted, total }) => ({ id, granted, total })), [{ id: 'users', granted: 2, total: 3 }]);
    assert.deepEqual(summarizeRole(catalog, []), []);
});

test('an operation without the permission that opens its page is reported, and nothing is added on its own', () => {
    assert.deepEqual(missingRequirements(catalog, ['create-users']).map((m) => [m.name, m.requires]), [['create-users', 'manage-users']]);
    assert.deepEqual(missingRequirements(catalog, ['create-users', 'manage-users']), []);
    assert.deepEqual(missingRequirements(catalog, ['manage-users']), []);
});

test('the search ignores case and accents and looks in label, description, name and resource', () => {
    const [users] = groupPermissions(catalog);
    assert.equal(normalize('ÁCCÉSO'), 'acceso');
    assert.equal(matchesSearch(catalog.items[0], users, 'acceder'), true);
    assert.equal(matchesSearch(catalog.items[0], users, 'ABRE el modulo'), true);
    assert.equal(matchesSearch(catalog.items[1], users, 'assign'), false);
    assert.equal(matchesSearch(catalog.items[1], users, 'usuarios y roles'), true);
    assert.equal(matchesSearch(catalog.items[1], users, ''), true);
});

test('the unsaved changes are the permissions added and removed', () => {
    assert.deepEqual(selectionDiff(['a', 'b'], ['b', 'c']), { added: ['c'], removed: ['a'] });
    assert.deepEqual(selectionDiff(['a'], ['a']), { added: [], removed: [] });
});
