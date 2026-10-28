import assert from "node:assert/strict";
import test from "node:test";
import { parse, parseDocument } from "yaml";
import { mergeOverride } from "../bin/setup-compose.mjs";

const root = "/var/azuracast";
const mount = "./plugins/azuracast-sweepers:/var/azuracast/www/plugins/azuracast-sweepers:ro";
const target = "/var/azuracast/www/plugins/azuracast-sweepers";

test("setup creates an override and is idempotent", () => {
    const output = mergeOverride("", root);
    assert.deepEqual(parse(output), { services: { web: {
        environment: { COMPOSER_PLUGIN_MODE: "true" }, volumes: [mount],
    } } });
    assert.equal(mergeOverride(output, root), output);
});

test("setup preserves Moonbase mounts, plugin flags, services and comments", () => {
    const input = `# Existing plugins
services:
  web:
    environment:
      AZURACAST_PLUGIN_MODE: true # Moonbase
      OTHER_SETTING: 'unchanged'
    volumes:
      - /home/cue_file/ls-config-replace:/var/azuracast/www/plugins/ls-config-replace
      - /usr/bin/cue_file:/usr/bin/cue_file
  redis:
    restart: always
`;
    const original = parse(input);
    const output = mergeOverride(input, root);
    const expected = structuredClone(original);
    expected.services.web.environment.COMPOSER_PLUGIN_MODE = "true";
    expected.services.web.volumes.push(mount);
    assert.deepEqual(parse(output), expected);
    assert.match(output, /# Existing plugins/);
    assert.match(output, /# Moonbase/);
    assert.equal(mergeOverride(output, root), output);
});

test("setup supports environment lists and read-only long bind syntax", () => {
    const input = `services:
  web:
    environment:
      - OTHER=value
      - COMPOSER_PLUGIN_MODE=false
    volumes:
      - type: bind
        source: /var/azuracast/plugins/azuracast-sweepers
        target: ${target}
        read_only: true
`;
    const data = parse(mergeOverride(input, root));
    assert.deepEqual(data.services.web.environment, ["OTHER=value", "COMPOSER_PLUGIN_MODE=true"]);
    assert.equal(data.services.web.volumes.length, 1);
    assert.deepEqual(data.services.web.volumes, parse(input).services.web.volumes);
});

test("setup adds an environment list entry and preserves interpolation", () => {
    const data = parse(mergeOverride("services:\n  web:\n    environment:\n      - OTHER=${OTHER}\n", root));
    assert.deepEqual(data.services.web.environment, ["OTHER=${OTHER}", "COMPOSER_PLUGIN_MODE=true"]);
});

test("setup fills empty environment and volumes keys instead of refusing them", () => {
    const data = parse(mergeOverride("services:\n  web:\n    environment:\n    volumes:\n", root));
    assert.deepEqual(data.services.web, { environment: { COMPOSER_PLUGIN_MODE: "true" }, volumes: [mount] });
});

test("setup preserves Compose reset and override tags outside modified sections", () => {
    const input = `services:
  web:
    logging: !reset
    ports: !override
      - "8080:80"
    labels: !override
      keep: unchanged
  redis:
    command: !reset []
`;
    const output = mergeOverride(input, root);
    const document = parseDocument(output);
    assert.equal(document.getIn(["services", "web", "logging"], true).tag, "!reset");
    assert.equal(document.getIn(["services", "web", "ports"], true).tag, "!override");
    assert.deepEqual(document.getIn(["services", "web", "ports"]).toJSON(), ["8080:80"]);
    assert.equal(document.getIn(["services", "web", "labels", "keep"]), "unchanged");
    assert.equal(document.getIn(["services", "redis", "command"], true).tag, "!reset");
    assert.equal(mergeOverride(output, root), output);
});

test("setup refuses ambiguous YAML or conflicting mounts instead of overwriting them", () => {
    for (const input of [
        "services: [web]",
        "services:\n  web:\n    environment: nope",
        "services:\n  web:\n    volumes: nope",
        "services:\n  web: {}\n  web: {}",
        "services:\n  web:\n    <<: {environment: {OTHER: yes}}",
        "services:\n  web:\n    environment:\n      - COMPOSER_PLUGIN_MODE=true\n      - COMPOSER_PLUGIN_MODE=false",
        `services:\n  web:\n    volumes:\n      - /wrong:${target}:ro`,
        `services:\n  web:\n    volumes:\n      - ./plugins/azuracast-sweepers:${target}`,
        `services:\n  web:\n    volumes:\n      - ${mount}\n      - ${mount}`,
        "services:\n  web:\n    volumes:\n      - ${UNKNOWN_MOUNT}",
        "services:\n  web:\n    volumes: !override []",
        "services:\n  web:\n    environment: !reset {}",
        "services:\n  web: !override {}",
        "services:\n  web:\n    logging: !unknown {}",
    ]) {
        assert.throws(() => mergeOverride(input, root), undefined, input);
    }
});
