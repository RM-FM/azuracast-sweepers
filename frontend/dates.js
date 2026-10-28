const DAY = 86400000;

function zoneParts(date, timeZone) {
    const parts = new Intl.DateTimeFormat("en-US", {
        timeZone, hourCycle: "h23", year: "numeric", month: "numeric", day: "numeric",
        hour: "numeric", minute: "numeric", second: "numeric",
    }).formatToParts(date);
    const value = (type) => Number(parts.find((part) => part.type === type).value);
    return { year: value("year"), month: value("month"), day: value("day"),
        hour: value("hour"), minute: value("minute"), second: value("second") };
}

function zoneOffset(date, timeZone) {
    const p = zoneParts(date, timeZone);
    return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) - Math.floor(date.getTime() / 1000) * 1000;
}

// Converts a wall-clock time in the station's zone to an instant; two passes settle DST transitions.
function zoneTime(year, month, day, timeZone) {
    const wall = Date.UTC(year, month - 1, day);
    let instant = wall - zoneOffset(new Date(wall), timeZone);
    instant = wall - zoneOffset(new Date(instant), timeZone);
    return new Date(instant);
}

export function startOfDay(date, timeZone, daysBack = 0) {
    const { year, month, day } = zoneParts(date, timeZone);
    return zoneTime(year, month, day - daysBack, timeZone);
}

export function endOfDay(date, timeZone, daysBack = 0) {
    const { year, month, day } = zoneParts(date, timeZone);
    return new Date(zoneTime(year, month, day - daysBack + 1, timeZone).getTime() - 1);
}

export function lastDay(now = new Date()) {
    return [new Date(now.getTime() - DAY), now];
}

// Mirrors AzuraCast's date range presets that fit into the timeline retention.
export function presetRanges(timeZone, retentionDays) {
    const now = () => new Date();
    return [
        { label: "Last 24 Hours", value: () => lastDay(now()) },
        { label: "Today", value: () => [startOfDay(now(), timeZone), endOfDay(now(), timeZone)] },
        { label: "Yesterday", value: () => [startOfDay(now(), timeZone, 1), endOfDay(now(), timeZone, 1)] },
        {
            label: `Last ${retentionDays} Days`,
            value: () => [startOfDay(now(), timeZone, retentionDays - 1), endOfDay(now(), timeZone)],
        },
    ];
}

export function formatStationTime(seconds, timeZone, options) {
    return new Date(seconds * 1000).toLocaleString(undefined, { ...options, timeZone });
}
