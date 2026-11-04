// Run with `npm run test:js`. The skeleton loading: which shape a page gets and which visits count as a navigation.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isNavigation, skeletonFor } from '../../resources/js/lib/skeleton.js';

const config = { paths: { dashboard: 'dashboard', orders: 'orders', customers: 'tabbed_table', bookings: 'agenda', reports: 'reports', conversations: 'conversations', settings: 'form', integrations: 'cards' }, default: 'table' };

test('the first segment of the path decides the shape, with or without query and sub-pages', () => {
    assert.equal(skeletonFor('/dashboard', config), 'dashboard');
    assert.equal(skeletonFor('/orders/12?tab=x', config), 'orders');
    assert.equal(skeletonFor('/customers', config), 'tabbed_table');
    assert.equal(skeletonFor('/bookings', config), 'agenda');
    assert.equal(skeletonFor('/reports', config), 'reports');
    assert.equal(skeletonFor('conversations?c=3', config), 'conversations');
    assert.equal(skeletonFor('/settings#colors', config), 'form');
    assert.equal(skeletonFor('/integrations', config), 'cards');
});

test('a module that is not listed, an empty path or no configuration use the default', () => {
    assert.equal(skeletonFor('/unknown/page', config), 'table');
    assert.equal(skeletonFor('', config), 'table');
    assert.equal(skeletonFor('/anything'), 'table');
    assert.equal(skeletonFor('/anything', { paths: {}, default: 'cards' }), 'cards');
});

test('only a plain GET to another page is a navigation', () => {
    assert.equal(isNavigation({ url: new URL('http://x.test/orders'), method: 'get' }, '/dashboard'), true);
    assert.equal(isNavigation({ url: '/orders?x=1', method: 'GET' }, '/dashboard'), true);
    assert.equal(isNavigation({ url: new URL('http://x.test/orders'), method: 'get' }, '/orders'), false, 'same page: filters, pagination, a refresh');
    assert.equal(isNavigation({ url: new URL('http://x.test/orders'), method: 'post' }, '/dashboard'), false, 'saving a form');
    assert.equal(isNavigation({ url: new URL('http://x.test/orders'), method: 'get', only: ['orders'] }, '/dashboard'), false, 'a partial reload');
    assert.equal(isNavigation({ url: new URL('http://x.test/orders'), method: 'get', prefetch: true }, '/dashboard'), false, 'an invisible prefetch');
    assert.equal(isNavigation(null, '/dashboard'), false);
});
