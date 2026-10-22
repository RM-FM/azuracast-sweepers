export function parseIntro(value, duration) {
    if (value === "" || value === null || value === undefined) {
        return null;
    }
    if (typeof value === "boolean" || !/^(?:\d+(?:\.\d*)?|\.\d+)$/.test(String(value).trim())) {
        throw new Error("Enter seconds from the original file start.");
    }
    const cue = Number(value);
    if (!Number.isFinite(cue) || cue < 0 || cue > duration) {
        throw new Error("The intro must be within this audio file.");
    }

    return cue;
}

export function displayIntro(row) {
    if (row.stale) {
        return "Recording changed — review required";
    }
    if (row.review?.status === "veto") {
        return "Veto — sweepers blocked";
    }
    if (row.review?.status === "unknown" || row.intro_end === null) {
        return "Not measured";
    }
    if (row.intro_end === 0) {
        return "No intro";
    }

    return `${row.intro_end.toFixed(2)} s${row.review?.kind === "30s+" ? "+" : ""}`;
}
