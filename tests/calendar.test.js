import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dateKey, shiftMonth } from '../resources/js/calendar.js';

test('month calculations cross year boundaries', () => {
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2027-01', -1), '2026-12');
    assert.equal(shiftMonth('2026-03', -1), '2026-02');
});

test('date keys use local calendar dates without UTC shifts', () => {
    assert.equal(dateKey(new Date(2026, 9, 27, 0, 0)), '2026-10-27');
    assert.equal(dateKey(new Date(2026, 9, 27, 23, 59)), '2026-10-27');
});
