// Run with `npm run test:js`. What the new-appointment form offers as the choices narrow: only professionals who perform the service and work in the sede.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { professionalsFor, servicesForLocation, stillCompatible } from '../../resources/js/lib/bookingCompat.js';

const pros = [
    { id: 1, name: 'David', serviceIds: [10], locationIds: [], everywhere: true },
    { id: 2, name: 'Laura', serviceIds: [10, 20], locationIds: [5], everywhere: false },
    { id: 3, name: 'Marta', serviceIds: [20], locationIds: [6], everywhere: false },
];

test('a professional who does not perform the service is not offered', () => {
    assert.deepEqual(professionalsFor(pros, 20, '').map((p) => p.name), ['Laura', 'Marta']); // David does beards, not colour
    assert.deepEqual(professionalsFor(pros, 10, '').map((p) => p.name), ['David', 'Laura']);
});

test('a professional who works only in another sede is not offered in the chosen one', () => {
    assert.deepEqual(professionalsFor(pros, 20, 5).map((p) => p.name), ['Laura']);
    assert.deepEqual(professionalsFor(pros, 20, 6).map((p) => p.name), ['Marta']);
    assert.deepEqual(professionalsFor(pros, 10, 6).map((p) => p.name), ['David']); // hours with no sede count everywhere
});

test('a choice made before is dropped when it stops fitting', () => {
    assert.equal(stillCompatible(pros, 2, 20, 5), true);
    assert.equal(stillCompatible(pros, 2, 20, 6), false);   // Laura does not work in sede 6
    assert.equal(stillCompatible(pros, 1, 20, ''), false);  // David does not perform colour
    assert.equal(stillCompatible(pros, '', 20, 6), true);   // «anyone available» is always valid
});

test('services are narrowed by the sede and unknown fields never exclude', () => {
    const services = [{ id: 1, everywhere: true, locationIds: [] }, { id: 2, everywhere: false, locationIds: [5] }, { id: 3, everywhere: false, locationIds: [6] }];

    assert.deepEqual(servicesForLocation(services, 5).map((s) => s.id), [1, 2]);
    assert.equal(servicesForLocation(services, '').length, 3);
    assert.deepEqual(professionalsFor([{ id: 9, name: 'X' }], 1, 5).map((p) => p.id), [9]);
});
