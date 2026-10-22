import assert from "node:assert/strict";
import { spawn, execFileSync } from "node:child_process";
import { once } from "node:events";
import { createServer } from "node:net";
import { randomBytes } from "node:crypto";
import { after, before, test } from "node:test";
import { fileURLToPath } from "node:url";

const cwd = fileURLToPath(new URL("../", import.meta.url));
const password = `Test-${randomBytes(12).toString("hex")}`;
const env = {
  ...process.env,
  APP_URL: "",
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_TIMEZONE: "UTC", // Server timezone remains fixed even with a conflicting environment.
  DB_DATABASE: `lottery_tj_test_api_${process.pid}`,
  SESSION_NAME: `lottery_test_${process.pid}`,
  LOTTERY_TEST_NOW: "2026-10-22 18:30:00",
  ADMIN_INITIAL_PASSWORD: password,
};
let server, origin, serverError;
let schedules, result17, result18, extraSchedule;
const php = (...args) =>
  execFileSync("php", args, {
    cwd,
    env,
    windowsHide: true,
    stdio: "pipe",
  }).toString();

before(async () => {
  php("bin/console", "migrate");
  php("bin/console", "admin:create");
  php("tests/lottery-fixture.php", "seed");
  const reservation = createServer();
  reservation.listen(0, "127.0.0.1");
  await once(reservation, "listening");
  const port = reservation.address().port;
  await new Promise((resolve) => reservation.close(resolve));
  origin = `http://127.0.0.1:${port}`;
  server = spawn(
    "php",
    ["-S", `127.0.0.1:${port}`, "-t", "public", "public/router.php"],
    { cwd, env, windowsHide: true, stdio: "pipe" },
  );
  server.on("error", (error) => {
    serverError = error;
  });
  server.stderr.resume();
  for (let i = 0; i < 50; i++) {
    if (serverError) throw serverError;
    try {
      if ((await fetch(`${origin}/api/health`)).ok) return;
    } catch {
      /* Starting PHP. */
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error("PHP test server did not start");
});
after(async () => {
  if (server && server.exitCode === null) {
    const closed = once(server, "close");
    server.kill();
    await closed;
  }
  php("tests/lottery-fixture.php", "cleanup");
});

function client() {
  let cookie = "",
    token = "";
  return async function send(method, path, body, withToken = true) {
    if (method !== "GET" && withToken && !token) await send("GET", "session");
    const response = await fetch(`${origin}/api/${path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(cookie ? { Cookie: cookie } : {}),
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(method !== "GET" && withToken ? { "X-CSRF-Token": token } : {}),
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    for (const value of response.headers.getSetCookie())
      cookie = value.split(";")[0];
    const data = await response.json();
    if (data.csrfToken) token = data.csrfToken;
    return { status: response.status, data, headers: response.headers };
  };
}
const admin = client();

test("public readers need no authentication and receive no admin secrets", async () => {
  const response = await client()("GET", "results");
  assert.equal(response.status, 200);
  assert.equal(response.data.date, "2026-10-22");
  assert.equal(response.data.timezone, "Asia/Yangon");
  assert.equal(response.data.results.length, 0);
  assert.equal(response.data.schedules.length, 6);
  assert.equal(response.data.latest, null);
  assert.doesNotMatch(
    JSON.stringify(response.data),
    /password|auth_version|username/,
  );
});
test("all admin operations reject anonymous requests", async () => {
  for (const [method, path, data] of [
    ["GET", "admin/schedules"],
    ["POST", "admin/results", {}],
    ["PUT", "admin/results/1", {}],
    ["DELETE", "admin/results/1"],
    ["POST", "admin/schedules", {}],
    ["PUT", "admin/schedules/1", {}],
    ["DELETE", "admin/schedules/1"],
    ["POST", "admin/password", {}],
    ["POST", "admin/logout", {}],
  ]) {
    assert.equal((await client()(method, path, data)).status, 401, path);
  }
});
test("login requires CSRF and accepts only the single valid admin", async () => {
  assert.equal(
    (
      await client()(
        "POST",
        "admin/login",
        { username: "admin", password },
        false,
      )
    ).status,
    419,
  );
  assert.equal(
    (await client()("POST", "admin/login", { username: "other", password }))
      .status,
    401,
  );
  assert.equal(
    (await admin("POST", "admin/login", { username: "admin", password }))
      .status,
    200,
  );
  const session = await admin("GET", "session");
  assert.deepEqual(session.data.admin, { id: 1, username: "admin" });
  schedules = (await admin("GET", "admin/schedules")).data.schedules;
});
test("schedule CRUD validates times and rejects duplicates", async () => {
  assert.equal(
    (await admin("POST", "admin/schedules", { time: "25:00" })).status,
    422,
  );
  assert.equal(
    (await admin("POST", "admin/schedules", { time: "17:00" })).status,
    409,
  );
  const created = await admin("POST", "admin/schedules", {
    time: "07:00",
    label: "နံနက်",
    active: true,
  });
  assert.equal(created.status, 201);
  extraSchedule = created.data.schedule;
  const updated = await admin("PUT", `admin/schedules/${extraSchedule.id}`, {
    time: "07:30",
    label: "Morning",
    active: false,
  });
  assert.equal(updated.status, 200);
  assert.equal(updated.data.schedule.time, "07:30");
  assert.equal(updated.data.schedule.active, false);
  assert.equal(
    (
      await admin("POST", "admin/results", {
        date: "2026-10-22",
        scheduleId: extraSchedule.id,
        number: "10",
      })
    ).status,
    422,
  );
  assert.equal(
    (await admin("DELETE", `admin/schedules/${extraSchedule.id}`)).status,
    200,
  );
  assert.equal(
    (await admin("DELETE", `admin/schedules/${extraSchedule.id}`)).status,
    404,
  );
});
test("future publication and invalid dates or non-two-digit numbers are blocked", async () => {
  for (const number of ["1", "123", "ab", 7, null, "၁၂", "01\n"]) {
    assert.equal(
      (
        await admin("POST", "admin/results", {
          date: "2026-10-22",
          scheduleId: schedules[0].id,
          number,
        })
      ).status,
      422,
    );
  }
  for (const date of ["2026-02-30", "tomorrow", "2026-10-23"]) {
    assert.equal(
      (
        await admin("POST", "admin/results", {
          date,
          scheduleId: schedules[0].id,
          number: "60",
        })
      ).status,
      422,
    );
  }
  assert.equal(
    (
      await admin("POST", "admin/results", {
        date: "2026-10-22",
        scheduleId: schedules[2].id,
        number: "60",
      })
    ).status,
    422,
  );
});
test("5PM publication produces a one-result cumulative snapshot", async () => {
  const created = await admin("POST", "admin/results", {
    date: "2026-10-22",
    scheduleId: schedules[0].id,
    number: "60",
  });
  assert.equal(created.status, 201);
  result17 = created.data.result;
  const day = (await client()("GET", "results")).data;
  assert.deepEqual(
    day.results.map((item) => item.number),
    ["60"],
  );
  assert.equal(day.latest.number, "60");
  assert.equal(day.next.time, "18:00");
  assert.match(day.serverNow, /\+06:30$/);
});
test("6PM publication updates the snapshot to include both results and preserves 07", async () => {
  const created = await admin("POST", "admin/results", {
    date: "2026-10-22",
    scheduleId: schedules[1].id,
    number: "07",
  });
  assert.equal(created.status, 201);
  result18 = created.data.result;
  const day = (await client()("GET", "results")).data;
  assert.deepEqual(
    day.results.map((item) => item.number),
    ["60", "07"],
  );
  assert.equal(day.latest.number, "07");
  assert.equal(day.next.time, "19:00");
  assert.equal(
    (
      await admin("POST", "admin/results", {
        date: "2026-10-22",
        scheduleId: schedules[1].id,
        number: "99",
      })
    ).status,
    409,
  );
  assert.equal(
    (await client()("GET", `results/${result18.id}`)).data.result.number,
    "07",
  );
});
test("editing and removing results is immediately reflected in public reads", async () => {
  assert.equal(
    (await admin("PUT", `admin/results/${result18.id}`, { number: "00" }))
      .status,
    200,
  );
  assert.equal((await client()("GET", "results")).data.latest.number, "00");
  assert.equal(
    (await admin("DELETE", `admin/results/${result18.id}`)).status,
    200,
  );
  assert.equal((await client()("GET", "results")).data.latest.number, "60");
  assert.equal((await client()("GET", `results/${result18.id}`)).status, 404);
});
test("calendar and history use actual published dates and validate input", async () => {
  const calendar = await client()("GET", "calendar?month=2026-10");
  assert.equal(
    calendar.data.days.find((day) => day.date === "2026-10-21").count,
    6,
  );
  assert.equal(
    calendar.data.days.find((day) => day.date === "2026-10-22").count,
    1,
  );
  const history = await client()("GET", "history");
  assert.deepEqual(
    history.data.days.map((day) => day.date),
    ["2026-10-22", "2026-10-21"],
  );
  const older = await client()("GET", "history?before=2026-10-22");
  assert.deepEqual(
    older.data.days.map((day) => day.date),
    ["2026-10-21"],
  );
  assert.equal((await client()("GET", "calendar?month=2026-99")).status, 422);
  assert.equal((await client()("GET", "results?date=2026-02-30")).status, 422);
});
test("schedule edits and deletion preserve historical times and numbers", async () => {
  assert.equal(
    (
      await admin("PUT", `admin/schedules/${schedules[0].id}`, {
        time: "16:30",
        label: "Changed",
        active: true,
      })
    ).status,
    200,
  );
  assert.equal(
    (await client()("GET", `results/${result17.id}`)).data.result.time,
    "17:00",
  );
  assert.equal(
    (await admin("DELETE", `admin/schedules/${schedules[0].id}`)).status,
    200,
  );
  const day = (await client()("GET", "results")).data;
  assert.equal(day.latest.number, "60");
  assert.equal(
    day.schedules.find((item) => item.id === schedules[0].id).time,
    "17:00",
  );
  assert.equal(
    (await client()("GET", "results?date=2026-10-21")).data.results.length,
    6,
  );
});
test("password change requires current password and revokes other sessions", async () => {
  const second = client();
  await second("POST", "admin/login", { username: "admin", password });
  assert.equal(
    (
      await admin("POST", "admin/password", {
        currentPassword: "bad",
        newPassword: "Changed-Test-Password!123",
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await admin("POST", "admin/password", {
        currentPassword: password,
        newPassword: "short",
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await admin("POST", "admin/password", {
        currentPassword: password,
        newPassword: "Changed-Test-Password!123",
      })
    ).status,
    200,
  );
  assert.equal((await second("GET", "admin/schedules")).status, 401);
  assert.equal((await admin("GET", "admin/schedules")).status, 200);
  assert.equal((await admin("POST", "admin/logout", {})).status, 200);
  assert.equal((await admin("GET", "admin/schedules")).status, 401);
});
test("login throttles repeated failures and never exposes password hashes", async () => {
  php("tests/lottery-fixture.php", "reset-rate-limit");
  const attacker = client();
  for (let i = 0; i < 5; i++)
    assert.equal(
      (
        await attacker("POST", "admin/login", {
          username: "admin",
          password: "wrong",
        })
      ).status,
      401,
    );
  const blocked = await attacker("POST", "admin/login", {
    username: "admin",
    password: "Changed-Test-Password!123",
  });
  assert.equal(blocked.status, 429);
  assert.ok(blocked.headers.get("retry-after"));
  assert.doesNotMatch(
    JSON.stringify(blocked.data),
    /password_hash|auth_version/,
  );
});

test("weekly seeding is repeatable, preserves data and provisions only a missing admin", () => {
  const checks = JSON.parse(php("tests/verify-seeder.php"));
  assert.equal(checks.inserted, 35);
  assert.equal(checks.repeatInserted, 0);
  assert.deepEqual(checks.times, ["08:00", "10:00", "12:00", "14:00", "16:00"]);
  assert.equal(checks.days, 7);
  for (const key of ["repeatCountUnchanged", "fivePerDay", "historicalOnly", "manualCorrectionPreserved",
    "originalResultsPreserved", "existingAdminPreserved", "missingAdminCreated", "singleAdmin", "localCredentialsUntouched"]) {
    assert.equal(checks[key], true, key);
  }
});
