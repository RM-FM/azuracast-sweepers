import assert from "node:assert/strict";
import test, { mock } from "node:test";
import { notifyError, notifySuccess, removeToast, toastDuration, toasts } from "../frontend/toasts.js";

test("toasts close on their own after AzuraCast's five seconds, longer text later", () => {
    assert.equal(toastDuration("Saved."), 5000);
    assert.equal(toastDuration("x".repeat(150)), 9000);
    assert.equal(toastDuration("x".repeat(1000)), 15000);
    mock.timers.enable({ apis: ["setTimeout"] });
    try {
        notifySuccess("Saved.");
        notifyError("Runpod rejected the key.");
        notifySuccess("");
        assert.deepEqual(toasts.map((toast) => [toast.message, toast.variant]),
            [["Saved.", "success"], ["Runpod rejected the key.", "danger"]]);
        removeToast(toasts[1].id);
        mock.timers.tick(4999);
        assert.equal(toasts.length, 1);
        mock.timers.tick(1);
        assert.equal(toasts.length, 0);
    } finally {
        mock.timers.reset();
    }
});