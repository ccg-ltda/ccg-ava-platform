import assert from 'node:assert/strict';
import test from 'node:test';
import { groupPermissions, permissionLabel } from '../../resources/js/lib/permissions.js';

const catalog = {
    groups: { users: 'Usuarios y roles', platform: 'Administración de la plataforma', empty: 'Vacío' },
    items: [
        { name: 'create-users', label: 'Crear usuarios', group: 'users' },
        { name: 'assign-roles', label: 'Asignar roles y membresías', group: 'users' },
        { name: 'manage-plans', label: 'Administrar planes comerciales', group: 'platform', platform: true },
    ],
};

test('permissions are grouped by area, in the order of the groups, without empty areas', () => {
    const groups = groupPermissions(catalog);

    assert.deepEqual(groups.map((group) => group.id), ['users', 'platform']);
    assert.deepEqual(groups[0].items.map((item) => item.name), ['create-users', 'assign-roles']);
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
