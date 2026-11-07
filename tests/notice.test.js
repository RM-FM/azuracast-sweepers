import assert from "node:assert/strict";
import test, { mock } from "node:test";
import { noticeDuration, useNotice } from "../frontend/notice.js";

test("notices stay long enough to read and then disappear", () => {
    assert.equal(noticeDuration("Saved."), 5000);
    assert.equal(noticeDuration("x".repeat(150)), 9000);
    assert.equal(noticeDuration("x".repeat(1000)), 15000);
    mock.timers.enable({ apis: ["setTimeout"] });
    try {
        const notice = useNotice();
        notice.value = "Saved.";
        mock.timers.tick(4000);
        notice.value = "";
        notice.value = "Saved.";
        mock.timers.tick(4000);
        assert.equal(notice.value, "Saved.", "Showing the notice again restarts its time");
        mock.timers.tick(1000);
        assert.equal(notice.value, "");
    } finally {
        mock.timers.reset();
    }
});