import { isMap, isScalar, isSeq, parseDocument } from "yaml";
import { lstatSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const target = "/var/azuracast/www/plugins/azuracast-sweepers";
const source = "./plugins/azuracast-sweepers";

function mapping(document, parent, key) {
    let value = parent.get(key, true);
    if (value === undefined || (isScalar(value) && value.value === null)) {
        value = document.createNode({});
        parent.set(key, value);
    }
    if (!isMap(value) || value.has("<<")) {
        throw new Error(`${key} must be an explicit YAML mapping, without aliases or merge keys.`);
    }
    return value;
}

export function mergeOverride(text, root) {
    const document = parseDocument(text, { uniqueKeys: true });
    if (document.errors.length) throw new Error(document.errors.map((error) => error.message).join("\n"));
    if (document.warnings.length) throw new Error(document.warnings.map((warning) => warning.message).join("\n"));
    document.contents ??= document.createNode({});
    if (!isMap(document.contents) || document.contents.has("<<")) {
        throw new Error("The Compose override must be an explicit YAML mapping.");
    }
    const web = mapping(document, mapping(document, document.contents, "services"), "web");
    const environment = web.get("environment", true);
    if (isSeq(environment)) {
        const entries = environment.items.filter((item) => isScalar(item) && typeof item.value === "string"
            && item.value.split("=")[0] === "COMPOSER_PLUGIN_MODE");
        if (entries.length > 1 || environment.items.some((item) => !isScalar(item) || typeof item.value !== "string")) {
            throw new Error("web.environment contains duplicate or unsupported entries.");
        }
        if (entries.length) entries[0].value = 'COMPOSER_PLUGIN_MODE=true';
        else environment.add("COMPOSER_PLUGIN_MODE=true");
    } else {
        mapping(document, web, "environment").set("COMPOSER_PLUGIN_MODE", "true");
    }
    let volumes = web.get("volumes", true);
    if (volumes === undefined || (isScalar(volumes) && volumes.value === null)) {
        volumes = document.createNode([]);
        web.set("volumes", volumes);
    }
    if (!isSeq(volumes)) throw new Error("web.volumes must be an explicit YAML list.");
    let found = false;
    for (const volume of volumes.items) {
        let mountSource;
        let mountTarget;
        let readOnly;
        if (isScalar(volume) && typeof volume.value === "string") {
            const parts = volume.value.split(":");
            if (parts.length > 3) throw new Error("Unsupported volume syntax; merge the plugin mount manually.");
            [mountSource, mountTarget] = parts;
            readOnly = parts[2]?.split(",").includes("ro") ?? false;
            if (parts.length === 1) mountTarget = mountSource;
        } else if (isMap(volume) && !volume.has("<<")) {
            mountSource = volume.get("source");
            mountTarget = volume.get("target");
            readOnly = volume.get("read_only") === true;
        } else {
            throw new Error("web.volumes contains an alias or unsupported mount; merge the plugin mount manually.");
        }
        if (typeof mountTarget !== "string" || mountTarget.includes("$")) {
            throw new Error("Cannot safely resolve a volume target; merge the plugin mount manually.");
        }
        if (mountTarget.replace(/\/+$/, "") !== target) continue;
        if (found) throw new Error("The plugin mount appears more than once.");
        found = true;
        if (typeof mountSource !== "string" || mountSource.includes("$")
            || resolve(root, mountSource) !== resolve(root, source) || !readOnly
            || (isMap(volume) && volume.get("type") !== "bind")) {
            throw new Error(`Conflicting plugin mount. Expected ${source}:${target}:ro; existing mount was not changed.`);
        }
    }
    if (!found) volumes.add(`${source}:${target}:ro`);
    return document.toString();
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    const [root, output] = process.argv.slice(2);
    if (!root || !output) throw new Error("Usage: node bin/setup-compose.mjs AZURACAST_DIR OUTPUT");
    const file = resolve(root, "docker-compose.override.yml");
    let text = "";
    let mode = 0o600;
    try {
        const stat = lstatSync(file);
        if (!stat.isFile()) throw new Error("The Compose override must be a regular file.");
        text = readFileSync(file, "utf8");
        mode = stat.mode & 0o777;
    } catch (error) {
        if (error.code !== "ENOENT") throw error;
    }
    writeFileSync(output, mergeOverride(text, root), { flag: "wx", mode });
}
