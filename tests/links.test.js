import assert from "node:assert/strict";
import test from "node:test";
import { linkKey, suggestLinks } from "../frontend/links.js";

test("link keys ignore case, punctuation, brackets and version labels", () => {
    assert.equal(linkKey({ title: "Hot Hits (Dry)", path: "a.mp3" }), "hot hits");
    assert.equal(linkKey({ title: "HOT-HITS v2 [Radio Edit]", path: "b.mp3" }), "hot hits");
    assert.equal(linkKey({ title: "Hot Hits – 10s Reverb", path: "c.mp3" }), "hot hits");
    assert.equal(linkKey({ title: null, path: "jingles/Weekend_Short.mp3" }), "weekend", "Untitled files use their file name");
    assert.notEqual(linkKey({ title: "Hot Hits Morning", path: "d.mp3" }), "hot hits", "Different wording stays apart");
});

test("suggestions group only tracks that share a key", () => {
    const suggestions = suggestLinks([
        { id: 1, title: "Hot Hits (Dry)", path: "1.mp3" },
        { id: 2, title: "Hot Hits v2", path: "2.mp3" },
        { id: 3, title: "Weekend", path: "3.mp3" },
        { id: 4, title: "", path: "(fx).mp3" },
    ]);
    assert.deepEqual(suggestions, [{ key: "hot hits", name: "Hot Hits (Dry)", media_ids: [1, 2] }]);
});
