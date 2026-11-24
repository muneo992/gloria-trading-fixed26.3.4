import assert from "node:assert/strict";
import { execFileSync, spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { setTimeout as delay } from "node:timers/promises";

const repo = dirname(dirname(fileURLToPath(import.meta.url)));
const sandbox = await mkdtemp(join(tmpdir(), "gloria-lhd-"));
const privateData = join(sandbox, "private");
const master = join(privateData, "lhd", "lhd-vehicles.json");
const password = "lhd-local-fixture";
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, "127.0.0.1", resolve));
const { port } = reservation.address();
await new Promise(resolve => reservation.close(resolve));

await mkdir(privateData);
const php = process.env.GLORIA_TEST_PHP || "php";
const server = spawn(php, [
    "-d", `session.save_path=${sandbox}`, "-d", "upload_max_filesize=8M", "-d", "post_max_size=10M",
    "-S", `127.0.0.1:${port}`, "-t", repo,
], {
    env: { ...process.env, GLORIA_WA_DATA_DIR: privateData, GLORIA_ADMIN_PASSWORD: password },
    stdio: ["ignore", "ignore", "pipe"],
});
let serverLog = "";
server.stderr.setEncoding("utf8").on("data", chunk => { serverLog += chunk; });
server.on("error", error => { serverLog += String(error); });
const stopped = new Promise(resolve => server.once("close", resolve));
const base = `http://127.0.0.1:${port}`;
const admin = "/admin/lhd.php";
const feed = "/frontend/data/lhd-vehicle-feed.php";
const image = "?image=";
let cookie = "";

function request(path, options = {}) {
    return fetch(base + path, { ...options, redirect: "manual" });
}

function authenticated(path, options = {}) {
    return request(path, { ...options, headers: { cookie } });
}

async function formFields(path) {
    const response = await authenticated(path);
    assert.equal(response.status, 200);
    const html = await response.text();
    return Object.fromEntries(Array.from(html.matchAll(/name="(csrf|version)" value="([a-f0-9]+)"/g), match => [match[1], match[2]]));
}

const today = new Date().toISOString().slice(0, 10);
const fixture = {
    make: "Example", model: "LHD QA Candidate", year: "2020", mileage: "42000",
    confirmed_on: today, expires_on: today, confirmed: "1", price_mode: "display",
    price: "8700.50", currency: "USD", trade_term: "CFR", destination_port: "Tema",
    supplier_name: "INTERNAL-FIXTURE", acquisition_cost: "1100", internal_profit: "400",
};

function save(path, fields) {
    return authenticated(path, { method: "POST", body: new URLSearchParams(fields) });
}

async function vehicleList() {
    const response = await request(feed);
    assert.equal(response.status, 200);
    assert.equal(response.headers.get("cache-control"), "no-store");
    return (await response.json()).vehicles;
}

try {
    for (let attempt = 0; attempt < 60; attempt++) {
        try {
            await request(feed);
            break;
        } catch (error) {
            if (attempt === 59 || server.exitCode !== null) throw new Error(serverLog, { cause: error });
            await delay(100);
        }
    }
    assert.deepEqual(await vehicleList(), []);
    assert.equal((await request(admin)).status, 303);
    assert.equal((await request(admin + "?ref=", { method: "POST" })).status, 303);
    const login = await request("/admin/index.php", {
        method: "POST", body: new URLSearchParams({ action: "login", password }),
    });
    assert.equal(login.status, 200);
    cookie = login.headers.get("set-cookie")?.split(";")[0];
    assert.ok(cookie);
    const newPage = admin + "?ref=";
    const initial = await formFields(newPage);
    assert.ok(initial.csrf && initial.version);
    assert.equal((await save(newPage, { ...fixture, action: "draft" })).status, 403);
    assert.equal((await save(newPage, { ...fixture, ...initial, action: "draft", destination_port: "" })).status, 422);
    assert.equal((await save(newPage, { ...fixture, ...initial, action: "draft", price: "-1" })).status, 422);

    const draft = new FormData();
    for (const [key, value] of Object.entries({ ...fixture, ...initial, action: "draft" })) draft.set(key, value);
    const png = execFileSync(php, ["-r", "imagepng(imagecreatetruecolor(2, 2));"]);
    draft.set("photo", new Blob([png], { type: "image/png" }), "fixture.png");
    const saved = await authenticated(newPage, { method: "POST", body: draft });
    assert.equal(saved.status, 303, await saved.text());
    const ref = new URL(saved.headers.get("location"), base + "/admin/").searchParams.get("ref");
    assert.match(ref, /^LHD-[A-F0-9]{12}$/);
    const edit = admin + "?ref=" + ref;
    assert.deepEqual(await vehicleList(), []);
    assert.equal((await request(feed + image + ref)).status, 404);
    assert.equal((await request(feed + "?image=..%2Flhd-vehicles.json")).status, 404);
    assert.equal((await request(edit + "&image=1")).status, 303);
    assert.equal((await authenticated(edit + "&image=1")).headers.get("content-type"), "image/jpeg");
    const preview = await authenticated(edit);
    assert.match(await preview.text(), /公開前プレビュー/);
    const draftFields = await formFields(edit);
    assert.equal((await save(edit, { ...draftFields, action: "publish" })).status, 422);
    assert.equal((await save(edit, { ...draftFields, action: "publish", approve: "1" })).status, 303);
    assert.equal((await save(edit, { ...draftFields, action: "stop" })).status, 422);
    const vehicles = await vehicleList();
    assert.deepEqual(vehicles, [{
        ref, has_image: true, make: "Example", model: "LHD QA Candidate", year: "2020", mileage: "42000",
        trade_term: "CFR", destination_port: "Tema", price: "8700.50", currency: "USD",
    }]);
    assert.equal((await request(feed + image + ref)).headers.get("content-type"), "image/jpeg");
    for (const path of ["/lhd/lhd-vehicles.json", "/frontend/data/lhd-vehicles.json"]) {
        const direct = await request(path);
        assert.doesNotMatch(direct.headers.get("content-type") ?? "", /application\/json/);
        assert.doesNotMatch(await direct.text(), new RegExp(ref));
    }
    assert.doesNotMatch(await readFile(master, "utf8"), /INTERNAL-FIXTURE|acquisition_cost|internal_profit/);

    let current = await formFields(edit);
    assert.equal((await save(edit, { ...current, action: "stop" })).status, 303);
    assert.deepEqual(await vehicleList(), []);
    assert.equal((await request(feed + image + ref)).status, 404);
    current = await formFields(edit);
    assert.equal((await save(edit, { ...fixture, ...current, action: "draft", price_mode: "quote", trade_term: "CIF" })).status, 303);
    current = await formFields(edit);
    assert.equal((await save(edit, { ...current, action: "publish", approve: "1" })).status, 303);
    const quoted = (await vehicleList())[0];
    assert.equal(quoted.trade_term, "CIF");
    assert.equal(quoted.destination_port, "Tema");
    assert.ok(!("price" in quoted) && !("currency" in quoted));

    const privateRecords = JSON.parse(await readFile(master, "utf8"));
    const dateBeforeToday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    privateRecords.vehicles[ref].confirmed_on = dateBeforeToday;
    privateRecords.vehicles[ref].expires_on = dateBeforeToday;
    await writeFile(master, JSON.stringify(privateRecords));
    assert.deepEqual(await vehicleList(), []);
    assert.equal((await request(feed + image + ref)).status, 404);
    current = await formFields(edit);
    assert.equal((await save(edit, { ...current, action: "publish", approve: "1" })).status, 422);
    await writeFile(master, "broken JSON");
    const failure = await request(feed);
    assert.equal(failure.status, 503);
    assert.deepEqual((await failure.json()).vehicles, []);
    console.log("PASS: LHD empty, draft, publish, pricing, image access, expiry, stop, conflicts, CSRF and failure isolation");
} finally {
    server.kill();
    await stopped;
    await rm(sandbox, { recursive: true, force: true });
}
