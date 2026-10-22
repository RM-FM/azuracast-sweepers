import assert from "node:assert/strict";
import test from "node:test";
import { displayIntro, parseIntro } from "../frontend/cues.js";

test("blank and zero remain distinct", () => {
    assert.equal(parseIntro("", 120), null);
    assert.equal(parseIntro(null, 120), null);
    assert.equal(parseIntro("0", 120), 0);
    assert.equal(parseIntro("14.25", 120), 14.25);
});

test("invalid or out-of-file boundaries cannot be saved", () => {
    for (const value of [-1, 121, NaN, Infinity, true, "30s+", "0x10", "garbage"]) {
        assert.throws(() => parseIntro(value, 120));
    }
});

test("instrumental, unknown, zero, veto and changed audio have separate labels", () => {
    assert.equal(displayIntro({ intro_end: 0 }), "No intro");
    assert.equal(displayIntro({ intro_end: null }), "Not measured");
    assert.equal(displayIntro({ intro_end: 30, review: { kind: "30s+" } }), "30.00 s+");
    assert.equal(displayIntro({ intro_end: 14.5, review: { kind: "musical" } }), "14.50 s");
    assert.match(displayIntro({ intro_end: 12, review: { status: "veto" } }), /blocked/);
    assert.match(displayIntro({ intro_end: 12, stale: true }), /changed/);
});
