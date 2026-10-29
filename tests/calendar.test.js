import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dateKey, monthCells, shiftMonth, monthLabel, formatDate } from '../resources/js/calendar.js';

test('October 2026 uses Monday first and includes every date exactly once', () => {
    const cells = monthCells('2026-10');
    assert.equal(cells.length, 42);
    assert.equal(cells[0].key, '2026-09-28');
    assert.equal(cells[3].key, '2026-10-01');
    assert.equal(cells.filter(cell => cell.inMonth).length, 31);
    assert.equal(new Set(cells.map(cell => cell.key)).size, 42);
});

test('February includes leap days only in leap years', () => {
    assert.equal(monthCells('2028-02').filter(cell => cell.inMonth).length, 29);
    assert.equal(monthCells('2026-02').filter(cell => cell.inMonth).length, 28);
});

test('month navigation crosses year boundaries', () => {
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2027-01', -1), '2026-12');
    assert.equal(shiftMonth('2026-03', -1), '2026-02');
});

test('date keys use local calendar dates without UTC shifts', () => {
    assert.equal(dateKey(new Date(2026, 9, 27, 0, 0)), '2026-10-27');
    assert.equal(dateKey(new Date(2026, 9, 27, 23, 59)), '2026-10-27');
});

test('calendar dates use English labels while keeping day-first dates', () => {
    assert.equal(monthLabel('2026-10'), 'October 2026');
    assert.equal(formatDate('2026-10-28'), '28 Oct');
    assert.equal(formatDate(null), 'No due date');
});
