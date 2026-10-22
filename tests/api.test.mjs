import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { createServer } from "node:net";
import { after, before, test } from "node:test";
import { fileURLToPath } from "node:url";

let server;
let origin;
let serverError;
let output = "";

before(async () => {
  const reservation = createServer();
  reservation.listen(0, "127.0.0.1");
  await once(reservation, "listening");
  const port = reservation.address().port;
  await new Promise((resolve) => reservation.close(resolve));
  origin = `http://127.0.0.1:${port}`;
  server = spawn(
    "php",
    ["-S", `127.0.0.1:${port}`, "-t", "public", "public/router.php"],
    {
      cwd: fileURLToPath(new URL("../", import.meta.url)),
      env: {
        ...process.env,
        APP_URL: "",
        APP_ENV: "testing",
        APP_DEBUG: "false",
        APP_TIMEZONE: "UTC",
        SESSION_NAME: "tj_api_test",
      },
      windowsHide: true,
    },
  );
  server.on("error", (error) => {
    serverError = error;
  });
  server.stderr.on("data", (chunk) => {
    output += chunk;
  });
  for (let attempt = 0; attempt < 50; attempt++) {
    if (serverError) throw serverError;
    if (server.exitCode !== null)
      throw new Error(`PHP server exited: ${output}`);
    try {
      const response = await fetch(`${origin}/api/health`);
      if (response.ok) return;
    } catch {
      /* PHP is still starting. */
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error(`PHP server did not start: ${output}`);
});

after(async () => {
  if (server && server.exitCode === null) {
    const closed = once(server, "close");
    server.kill();
    await closed;
  }
});

async function session() {
  const response = await fetch(`${origin}/api/session`);
  assert.equal(response.status, 200);
  assert.match(response.headers.get("cache-control"), /no-store/);
  return {
    cookie: response.headers.get("set-cookie").split(";")[0],
    token: (await response.json()).csrfToken,
  };
}

function post(body, auth = {}, contentType = "application/json") {
  return fetch(`${origin}/api/welcome`, {
    method: "POST",
    headers: {
      "Content-Type": contentType,
      ...(auth.cookie ? { Cookie: auth.cookie } : {}),
      ...(auth.token ? { "X-CSRF-Token": auth.token } : {}),
    },
    body,
  });
}

test("health responds with JSON without a database", async () => {
  for (const path of ["/health", "/api/health"]) {
    const response = await fetch(`${origin}${path}`);
    assert.equal(response.status, 200);
    assert.match(response.headers.get("content-type"), /application\/json/);
    assert.match(response.headers.get("cache-control"), /no-store/);
    const data = await response.json();
    assert.equal(data.status, "ok");
    assert.equal(data.timezone, "Asia/Yangon");
    assert.match(data.serverTime, /\+06:30$/);

    const head = await fetch(`${origin}${path}`, { method: "HEAD" });
    assert.equal(head.status, 200);
    assert.equal(await head.text(), "");
  }
});

test("API responses allow any origin, including error responses", async () => {
  for (const domain of ["https://client.example", "https://another.example"]) {
    for (const path of ["/api/health", "/api/not-found"]) {
      const response = await fetch(`${origin}${path}`, {
        headers: { Origin: domain },
      });
      assert.equal(response.status, path === "/api/health" ? 200 : 404);
      assert.equal(response.headers.get("access-control-allow-origin"), "*");
      assert.equal(response.headers.get("access-control-allow-credentials"), null);
    }
  }
});

test("CORS preflight supports JSON and CSRF headers without authentication", async () => {
  const response = await fetch(`${origin}/api/admin/schedules/1`, {
    method: "OPTIONS",
    headers: {
      Origin: "https://client.example",
      "Access-Control-Request-Method": "PUT",
      "Access-Control-Request-Headers": "content-type,x-csrf-token",
    },
  });
  assert.equal(response.status, 204);
  assert.equal(await response.text(), "");
  assert.equal(response.headers.get("access-control-allow-origin"), "*");
  assert.match(response.headers.get("access-control-allow-methods"), /PUT/);
  assert.match(response.headers.get("access-control-allow-headers"), /Content-Type/);
  assert.match(response.headers.get("access-control-allow-headers"), /X-CSRF-Token/);
});

test("session uses an HttpOnly cookie and a stable CSRF token", async () => {
  const response = await fetch(`${origin}/api/session`);
  assert.match(response.headers.get("set-cookie"), /HttpOnly/i);
  assert.match(response.headers.get("set-cookie"), /SameSite=Lax/i);
  const token = (await response.json()).csrfToken;
  assert.match(token, /^[a-f0-9]{64}$/);
  const next = await fetch(`${origin}/api/session`, {
    headers: { Cookie: response.headers.get("set-cookie").split(";")[0] },
  });
  assert.equal((await next.json()).csrfToken, token);
});

test("writes require both the session cookie and matching CSRF token", async () => {
  const auth = await session();
  for (const credentials of [
    {},
    { cookie: auth.cookie, token: "bad" },
    { token: auth.token },
  ]) {
    const response = await post('{"name":"Test"}', credentials);
    assert.equal(response.status, 419);
    assert.equal((await response.json()).message, "Session expired");
  }
});

test("JSON POST supports Burmese names", async () => {
  const response = await post(
    JSON.stringify({ name: "မောင်မောင်" }),
    await session(),
  );
  assert.equal(response.status, 200);
  assert.match((await response.json()).message, /မောင်မောင်/);
});

test("invalid name values return field errors with status 422", async () => {
  const auth = await session();
  for (const name of ["", "  ", [], {}, 123, "က".repeat(81)]) {
    const response = await post(JSON.stringify({ name }), auth);
    assert.equal(response.status, 422);
    assert.ok((await response.json()).errors.name);
  }
});

test("malformed JSON and non-object JSON return 400", async () => {
  const auth = await session();
  for (const body of ["{", "[]", "null", '"hello"']) {
    const response = await post(body, auth);
    assert.equal(response.status, 400);
    assert.ok((await response.json()).message);
  }
});

test("non-JSON writes return 415 after CSRF verification", async () => {
  const response = await post(
    "name=Test",
    await session(),
    "application/x-www-form-urlencoded",
  );
  assert.equal(response.status, 415);
});

test("unknown API paths and unsupported methods return JSON errors", async () => {
  const missing = await fetch(`${origin}/api/unknown`);
  assert.equal(missing.status, 404);
  assert.equal((await missing.json()).message, "Page not found");
  const method = await fetch(`${origin}/api/health`, { method: "POST" });
  assert.equal(method.status, 405);
  assert.match(method.headers.get("allow"), /GET/);
  assert.equal((await method.json()).message, "Method not allowed");
});

test("HEAD API responses have no body", async () => {
  const response = await fetch(`${origin}/api/health`, { method: "HEAD" });
  assert.equal(response.status, 200);
  assert.equal(await response.text(), "");
});

test("React deep links receive the shell and built assets resolve", async () => {
  const response = await fetch(`${origin}/calendar`);
  assert.equal(response.status, 200, "Run npm run build before npm test.");
  const html = await response.text();
  assert.match(html, /<base href="\/build\/">/);
  assert.match(html, /name="app-base" content="\/"/);
  assert.match(html, /id="root"/);
  const script = html.match(/src="\.\/(assets\/[^"]+\.js)"/);
  assert.ok(script, "Built module script is present");
  assert.equal((await fetch(`${origin}/build/${script[1]}`)).status, 200);
  assert.equal((await fetch(`${origin}/api/connection`)).status, 404);
});

test("original PHP form keeps its CSRF protection", async () => {
  const page = await fetch(`${origin}/starter`);
  assert.equal(page.status, 200);
  const html = await page.text();
  const token = html.match(/name="_token" value="([a-f0-9]+)"/)[1];
  const cookie = page.headers.get("set-cookie").split(";")[0];
  const invalid = await fetch(`${origin}/welcome`, {
    method: "POST",
    body: new URLSearchParams({ name: "Test" }),
  });
  assert.equal(invalid.status, 419);
  const valid = await fetch(`${origin}/welcome`, {
    method: "POST",
    headers: { Cookie: cookie },
    body: new URLSearchParams({ name: "Test", _token: token }),
  });
  assert.equal(valid.status, 200);
  assert.match(await valid.text(), /Hello, Test!/);
});
