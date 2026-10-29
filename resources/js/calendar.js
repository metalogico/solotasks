export function dateKey(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function currentMonth() {
    return dateKey().slice(0, 7);
}

export function monthLabel(month) {
    return new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${month}-01T12:00:00`));
}

export function shiftMonth(month, offset) {
    const [year, monthNumber] = month.split('-').map(Number);
    return dateKey(new Date(year, monthNumber - 1 + offset, 1, 12)).slice(0, 7);
}

export function monthCells(month) {
    const [year, monthNumber] = month.split('-').map(Number);
    // Local noon keeps date-only values away from UTC and midnight DST changes.
    const firstDay = new Date(year, monthNumber - 1, 1, 12);
    const mondayOffset = (firstDay.getDay() + 6) % 7;

    return Array.from({ length: 42 }, (_, cellIndex) => {
        const date = new Date(year, monthNumber - 1, cellIndex - mondayOffset + 1, 12);
        const key = dateKey(date);

        return {
            key,
            day: date.getDate(),
            inMonth: key.startsWith(month),
            today: key === dateKey(),
        };
    });
}

export function formatDate(date) {
    if (!date) {
        return 'No due date';
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T12:00:00`));
}

export const statuses = [
    { key: 'backlog', name: 'Backlog', color: '#84918b' },
    { key: 'todo', name: 'Todo', color: '#b89b59' },
    { key: 'in_progress', name: 'In progress', color: '#4c8374' },
    { key: 'in_review', name: 'In review', color: '#8b79ad' },
    { key: 'done', name: 'Done', color: '#538b5a' },
];

export const priorities = { high: 'High', medium: 'Medium', low: 'Low' };
