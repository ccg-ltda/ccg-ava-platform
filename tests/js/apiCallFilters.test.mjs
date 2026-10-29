import assert from 'node:assert/strict';
import test from 'node:test';
import { activeFilterCount, callQuery, cleanStatus, EMPTY_FILTERS } from '../../resources/js/lib/apiCallFilters.js';

test('no filter sends no parameter and counts zero', () => {
    assert.deepEqual(callQuery(EMPTY_FILTERS), {});
    assert.equal(activeFilterCount(EMPTY_FILTERS), 0);
});

test('combined filters are all sent and counted; the page only travels after the first', () => {
    const filters = { ...EMPTY_FILTERS, integration: 'wompi', outcome: 'timeout', status: '503', from: '2026-10-01', to: '2026-10-31' };

    assert.deepEqual(callQuery(filters), { integration: 'wompi', outcome: 'timeout', status: '503', from: '2026-10-01', to: '2026-10-31' });
    assert.equal(activeFilterCount(filters), 5);
    assert.deepEqual(callQuery(filters, 1).page, undefined);
    assert.equal(callQuery(filters, 3).page, 3);
});

test('clearing the filters goes back to the empty query', () => {
    assert.deepEqual(callQuery({ ...EMPTY_FILTERS }), {});
});

test('unknown keys never reach the server and the HTTP code keeps only up to three digits', () => {
    assert.deepEqual(callQuery({ ...EMPTY_FILTERS, workspace_id: '9', operation: 'notify' }), { operation: 'notify' });
    assert.equal(cleanStatus('5a0b3x9'), '503');
    assert.equal(cleanStatus(null), '');
});
