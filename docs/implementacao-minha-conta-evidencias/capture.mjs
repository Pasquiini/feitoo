// Redesign validation harness. Run from monorepo root. No production API or Stripe access.
import { createServer } from "node:http";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { resolve, extname } from "node:path";
import { withBrowser } from "../../feitoo-web/scripts/browser-smoke.mjs";

const out = resolve("docs/implementacao-minha-conta-evidencias");
const root = resolve("feitoo-web/dist/feitoo-web/browser");
await mkdir(out, { recursive: true });
let scenario = "free-zero",
  mode = "normal";
const extras =
  process.argv.includes("--extras") ||
  process.env.FEITOO_ACCOUNT_AUDIT_EXTRAS === "1";
const previous = async (name) =>
  extras
    ? JSON.parse(await readFile(resolve(out, name + ".json"), "utf8"))
    : [];
const log = await previous("requests"),
  measurements = await previous("measurements"),
  checks = await previous("checks");
const user = {
  id: 900001,
  name: "Conta de auditoria simulada",
  email: "auditoria@example.test",
  has_password: true,
  google_linked: false,
  created_at: "2026-10-01T12:00:00Z",
};
const orderId = "12345678-1234-4234-8234-123456789abc";
function subscription() {
  const states = {
    "pro-active": "active",
    "pro-pending": "incomplete",
    "past-due": "past_due",
    unpaid: "unpaid",
    canceled: "canceled",
    "incomplete-expired": "incomplete_expired",
    scheduled: "active",
    "period-expired": "active",
    paused: "paused",
    trialing: "trialing",
    "return-confirming": "incomplete",
    "return-cancelled": "incomplete",
  };
  const status =
    scenario === "remote-incomplete" ? "incomplete" : states[scenario];
  return status
    ? {
        status,
        amount: 2990,
        currency: "brl",
        grants_pro: ["pro-active", "scheduled"].includes(scenario),
        current_period_end:
          scenario === "period-expired"
            ? "2026-10-01T00:00:00-03:00"
            : "2026-11-28T20:00:00-03:00",
        cancel_at_period_end: scenario === "scheduled",
      }
    : null;
}
function quota() {
  const active = subscription()?.grants_pro ?? false;
  const credits = scenario === "free-credits" || mode === "credit-paid" ? 5 : 0;
  return {
    source: active ? "pro" : "free",
    policy: "explicit_source",
    enabled: true,
    daily_limit: 1,
    remaining: scenario === "free-zero" ? 1 : 0,
    reserved: 0,
    consumed: scenario === "free-zero" ? 0 : 1,
    resets_at: "2026-10-30T00:00:00-03:00",
    active_processing: null,
    latest_processing: null,
    credits: {
      granted: credits,
      available: credits,
      reserved: 0,
      consumed: 0,
      revoked: 0,
    },
    pro: {
      active,
      limit: 100,
      remaining: active ? 82 : 0,
      reserved: 0,
      consumed: active ? 18 : 0,
      period_end: "2026-11-28T20:00:00-03:00",
    },
    offer: {
      available: scenario !== "offer-unavailable",
      credits: 5,
      amount: 990,
      code: "quotation_5",
      currency: "brl",
    },
  };
}
function history(page) {
  return Array.from({ length: 10 }, (_, i) => ({
    id: `00000000-0000-4000-8000-${String(page * 10 + i).padStart(12, "0")}`,
    tool: [
      "xml-to-excel",
      "products-from-xml",
      "docx_to_pdf",
      "quotation-generator",
    ][i % 4],
    status: "completed",
    created_at: "2026-10-28T15:00:00-03:00",
    expires_at: "2099-01-01T00:00:00Z",
    files_count: i % 4 === 3 ? 1 : 3,
    successful_files_count: i % 4 === 3 ? 1 : 3,
    failed_files_count: 0,
    total_value: i % 4 < 2 ? "12345.67" : null,
    products_summary:
      i % 4 === 1
        ? { items_count: 12, products_count: 8, total_value: "12345.67" }
        : null,
    files_deleted: false,
    can_delete_files: true,
    result: { available: true, file_name: "resultado-simulado.pdf" },
  }));
}
const api = createServer(async (req, res) => {
  const url = new URL(req.url, "http://localhost:8000");
  res.setHeader(
    "Access-Control-Allow-Origin",
    req.headers.origin || "http://localhost:4317",
  );
  res.setHeader("Access-Control-Allow-Credentials", "true");
  res.setHeader(
    "Access-Control-Allow-Headers",
    "Accept,Content-Type,X-Quotation-Owner,Idempotency-Key,X-XSRF-TOKEN",
  );
  res.setHeader(
    "Access-Control-Allow-Methods",
    "GET,POST,PATCH,DELETE,OPTIONS",
  );
  if (req.method === "OPTIONS") return res.writeHead(204).end();
  log.push({
    scenario,
    mode,
    method: req.method,
    path: url.pathname,
    query: url.search,
    owner: req.headers["x-quotation-owner"] ?? null,
  });
  const json = (status, value) => {
    res.setHeader("Content-Type", "application/json");
    res.setHeader("Cache-Control", "no-store, private");
    res.writeHead(status).end(JSON.stringify(value));
  };
  if (url.pathname === "/api/health")
    return json(200, { status: "ok", service: "feitoo-api" });
  if (url.pathname === "/sanctum/csrf-cookie") {
    res.setHeader(
      "Set-Cookie",
      "XSRF-TOKEN=synthetic-csrf; Path=/; SameSite=Lax",
    );
    return res.writeHead(204).end();
  }
  if (url.pathname === "/api/me")
    return json(scenario === "session-expired" ? 401 : 200, { data: user });
  if (scenario === "loading") await new Promise((r) => setTimeout(r, 6500));
  if (
    scenario === "api-error" ||
    (scenario === "subscription-error" &&
      url.pathname === "/api/account/subscription")
  )
    return json(500, { message: "Falha sintética de API." });
  if (mode === "quota-error" && url.pathname === "/api/quotations/entitlements")
    return json(500, { message: "Falha sintética de saldo." });
  if (
    ["billing-expired", "billing-419"].includes(mode) &&
    url.pathname.startsWith("/api/account/subscription")
  )
    return json(mode === "billing-419" ? 419 : 401, {
      message: "Sessão simulada expirada.",
    });
  if (mode === "history-expired" && url.pathname === "/api/processings")
    return json(401, { message: "Sessão simulada expirada." });
  if (url.pathname === "/api/account/usage") {
    const active = subscription()?.grants_pro ?? false;
    return json(200, {
      data: {
        plan: active ? "pro" : "free",
        limits: {
          per_processing: active ? 250 : 3,
          daily: active ? 500 : 6,
          used_today: active ? 32 : 0,
          remaining_today: active ? 468 : 6,
          resets_at: "2099-01-01T00:00:00Z",
        },
      },
    });
  }
  if (url.pathname === "/api/account/profile")
    return json(200, { data: { ...user, name: "Nome atualizado" } });
  if (url.pathname === "/api/account/password")
    return json(200, { message: "ok" });
  if (url.pathname === "/api/auth/google/start")
    return json(503, { code: "unavailable" });
  if (url.pathname.match(/^\/api\/processings\/[^/]+\/result$/)) {
    res.setHeader(
      "Content-Disposition",
      'attachment; filename="resultado.zip"',
    );
    return res.writeHead(200).end("SYNTHETIC-DOWNLOAD");
  }
  if (req.method === "DELETE" && url.pathname.startsWith("/api/processings/"))
    return json(200, { data: {} });
  if (url.pathname === "/api/account/subscription")
    return json(200, {
      data: {
        state:
          scenario === "pro-pending" || scenario.startsWith("return-")
            ? "checkout_pending"
            : scenario === "remote-incomplete"
              ? "payment_pending"
              : ["past-due", "unpaid"].includes(scenario)
                ? "payment_due"
                : scenario === "scheduled"
                  ? "cancel_scheduled"
                  : scenario === "period-expired"
                    ? "period_ended"
                    : (subscription()?.status ?? "free"),
        actions: {
          checkout:
            scenario !== "offer-unavailable" &&
            (!subscription() ||
              ["canceled", "incomplete-expired"].includes(scenario)),
          resume_checkout: [
            "pro-pending",
            "return-confirming",
            "return-cancelled",
          ].includes(scenario),
          portal: !!subscription(),
          refresh: true,
        },
        offer: {
          amount: 2990,
          currency: "brl",
          per_processing: 250,
          daily: 500,
          available: scenario !== "offer-unavailable",
        },
        subscription: subscription(),
      },
    });
  if (
    url.pathname.endsWith("/subscription/checkout") ||
    url.pathname.endsWith("/subscription/portal")
  )
    return json(mode === "checkout-409" ? 409 : 503, {
      code: "checkout_unavailable",
      message: "Resposta simulada: nenhuma sessão Stripe criada.",
    });
  if (url.pathname === "/api/quotations/entitlements")
    return json(200, { data: quota() });
  if (
    url.pathname === "/api/quotations/credit-orders" &&
    req.method === "POST"
  ) {
    let body = "";
    for await (const part of req) body += part;
    log.push({
      scenario,
      mode,
      method: "CHECKOUT",
      key: req.headers["idempotency-key"],
      body: JSON.parse(body || "{}"),
    });
    return json(201, {
      data: {
        id: orderId,
        status: "pending",
        amount: 990,
        credits: 5,
        currency: "brl",
        checkout_url: "https://checkout.stripe.com/c/pay/SIMULATED_DO_NOT_OPEN",
        created_at: "2026-10-28T16:00:00-03:00",
        paid_at: null,
        refunded_amount: 0,
        actions: { refresh: true, resume_checkout: true },
      },
    });
  }
  if (url.pathname === "/api/quotations/credit-orders/" + orderId)
    return json(200, {
      data: {
        id: orderId,
        status: mode === "credit-paid" ? "paid" : "pending",
        amount: 990,
        credits: 5,
        currency: "brl",
        checkout_url:
          mode === "credit-paid"
            ? null
            : "https://checkout.stripe.com/c/pay/SIMULATED_DO_NOT_OPEN",
        created_at: "2026-10-28T16:00:00-03:00",
        paid_at: mode === "credit-paid" ? "2026-10-28T16:01:00-03:00" : null,
        refunded_amount: 0,
        actions: {
          refresh: mode !== "credit-paid",
          resume_checkout: mode !== "credit-paid",
        },
      },
    });
  if (url.pathname === "/api/quotations/credit-orders")
    return json(200, {
      data: ["purchase-pending", "many-history"].includes(scenario)
        ? [
            {
              id: orderId,
              status: "pending",
              amount: 990,
              credits: 5,
              currency: "brl",
              actions: { refresh: true, resume_checkout: true },
              checkout_url:
                "https://checkout.stripe.com/c/pay/SIMULATED_DO_NOT_OPEN",
              created_at: "2026-10-28T16:00:00-03:00",
              paid_at: null,
              refunded_amount: 0,
            },
          ]
        : [],
      meta: { current_page: 1, last_page: 1 },
    });
  if (url.pathname === "/api/account/purchases")
    return json(200, {
      data:
        scenario === "many-history"
          ? [
              {
                id: "xml-synthetic",
                processing_id: orderId,
                tool: "xml-to-excel",
                package_code: "xml_100",
                files_limit: 100,
                files_count: 75,
                amount: 490,
                currency: "brl",
                status: "paid",
                consumed: false,
                paid_at: "2026-10-28T14:00:00Z",
                expires_at: "2099-01-01T00:00:00Z",
                created_at: "2026-10-28T14:00:00Z",
                can_resume_processing: true,
              },
            ]
          : [],
      meta: { current_page: 1, last_page: 1 },
    });
  if (url.pathname === "/api/processings") {
    const page = Number(url.searchParams.get("page") || 1);
    return json(200, {
      data:
        scenario === "many-history"
          ? history(page).slice(
              0,
              Number(url.searchParams.get("per_page") || 10),
            )
          : [],
      meta: {
        current_page: page,
        last_page: scenario === "many-history" ? 5 : 1,
        total: scenario === "many-history" ? 45 : 0,
        per_page: 10,
      },
    });
  }
  if (url.pathname === "/api/quotations/drafts")
    return json(200, { data: null });
  return json(503, {
    message: "Endpoint sem fixture: nenhuma chamada real permitida.",
  });
});
const web = createServer(async (req, res) => {
  const path = decodeURIComponent(
    new URL(req.url, "http://localhost").pathname,
  );
  const file = resolve(root, "." + path);
  if (!file.startsWith(root)) return res.writeHead(403).end();
  try {
    const data = await readFile(
      extname(path) ? file : resolve(root, "index.csr.html"),
    );
    res.setHeader(
      "Content-Type",
      {
        ".js": "text/javascript",
        ".css": "text/css",
        ".svg": "image/svg+xml",
        ".png": "image/png",
      }[extname(path)] || "text/html",
    );
    // Test transport only: the production bundle is served against the isolated local contract fake.
    // The compiled files and application environment remain unchanged on disk.
    res.end(
      extname(path) === ".js"
        ? data
            .toString()
            .replaceAll("https://api.feitoo.com.br", "http://localhost:8000")
        : data,
    );
  } catch {
    res.writeHead(404).end();
  }
});
await new Promise((r, reject) => {
  api.once("error", reject);
  api.listen(8000, "localhost", r);
});
await new Promise((r, reject) => {
  web.once("error", reject);
  web.listen(4317, "localhost", r);
});
const originalCwd = process.cwd();
process.chdir(resolve("feitoo-web"));
try {
  await withBrowser(
    "account-redesign",
    async ({ command, evaluate, waitFor, errors }) => {
      await command("Network.setBlockedURLs", {
        urls: ["https://*", "http://api.feitoo.com.br/*"],
      });
      await command("Emulation.setTimezoneOverride", {
        timezoneId: "America/Sao_Paulo",
      });
      const delay = (ms) => new Promise((r) => setTimeout(r, ms));
      const check = (name, ok, detail) => {
        checks.push({ name, ok, detail });
        if (!ok) throw new Error(name + ": " + JSON.stringify(detail));
      };
      const viewport = (width) =>
        command("Emulation.setDeviceMetricsOverride", {
          width,
          height: 900,
          deviceScaleFactor: 1,
          mobile: false,
        });
      async function navigate(path = "/conta") {
        await command("Page.navigate", { url: "http://localhost:4317" + path });
        await waitFor(
          "!!document.querySelector('app-account-page,app-account-subscription,app-account-credits,app-account-files,app-account-profile,app-auth-page')",
        );
        await delay(scenario === "loading" ? 150 : 350);
      }
      async function click(text) {
        return evaluate(
          `(() => { const e=[...document.querySelectorAll('app-account-shell a,app-account-shell button')].find(e=>e.textContent.trim()===${JSON.stringify(text)});if(!e)throw Error('Missing action');e.click();return e.getAttribute('href');})()`,
        );
      }
      async function snap(name, width) {
        await evaluate("window.scrollTo(0,0)");
        const geometry = await evaluate(
          `(() => { const shell=document.querySelector('app-account-shell');const box=e=>{const r=e.getBoundingClientRect();return {text:e.textContent.trim(),y:Math.round(r.top),bottom:Math.round(r.bottom)}};return {url:location.pathname+location.search+location.hash,width:innerWidth,scrollWidth:document.documentElement.scrollWidth,height:Math.round(shell?.getBoundingClientRect().height??0),words:shell?.innerText.trim().split(/\\s+/).length,focus:document.activeElement?.tagName,actions:[...shell.querySelectorAll('a,button')].filter(e=>e.getClientRects().length).map(box),status:[...shell.querySelectorAll('[role="alert"],[role="status"]')].map(e=>e.textContent.trim())};})()`,
        );
        measurements.push({ name, scenario, ...geometry });
        if (geometry.url.includes("?"))
          check(
            "Return context keeps aria-current " + name,
            await evaluate(
              "document.querySelector('.account-nav [aria-current=page]')?.getAttribute('href') === location.pathname",
            ),
            geometry.url,
          );
        check(
          "No horizontal page overflow " + name,
          geometry.scrollWidth <= width,
          geometry.scrollWidth,
        );
        const metrics = await command("Page.getLayoutMetrics");
        const { data } = await command("Page.captureScreenshot", {
          format: "png",
          captureBeyondViewport: true,
          clip: {
            x: 0,
            y: 0,
            width,
            height: Math.ceil(metrics.cssContentSize.height),
            scale: 1,
          },
        });
        await writeFile(
          resolve(out, name + ".png"),
          Buffer.from(data, "base64"),
        );
        return geometry;
      }
      const routes = [
        ["visao-geral", "/conta"],
        ["assinatura", "/conta/assinatura"],
        ["creditos", "/conta/creditos"],
        ["arquivos", "/conta/arquivos"],
        ["perfil", "/conta/perfil"],
      ];
      for (const width of process.env.FEITOO_ACCOUNT_QUICK
        ? []
        : [320, 375, 390, 768, 1024, 1440]) {
        await viewport(width);
        scenario = "free-zero";
        mode = "normal";
        for (const [name, path] of routes) {
          await navigate(path);
          const g = await snap(name + "-" + width, width);
          if (width === 390 && name === "assinatura")
            check(
              "Subscription price status and CTA above fold",
              g.actions.some(
                (a) => a.text === "Assinar Feitoo Pro" && a.bottom < 900,
              ),
              g.actions,
            );
          if (width === 390 && name === "creditos")
            check(
              "Direct credit purchase above fold",
              g.actions.some(
                (a) => a.text === "Comprar 5 créditos" && a.bottom < 900,
              ),
              g.actions,
            );
        }
      }
      await viewport(390);
      scenario = "many-history";
      await navigate();
      const recent = await evaluate(
        "document.querySelectorAll('.recent-list li').length",
      );
      check("Overview limited to three files", recent === 3, recent);
      await snap("overview-three-files-390", 390);
      await evaluate("document.querySelectorAll('.account-nav a')[2].focus()");
      await command("Input.dispatchKeyEvent", {
        type: "keyDown",
        key: "Enter",
        code: "Enter",
        windowsVirtualKeyCode: 13,
      });
      await command("Input.dispatchKeyEvent", {
        type: "keyUp",
        key: "Enter",
        code: "Enter",
        windowsVirtualKeyCode: 13,
      });
      await waitFor("location.pathname==='/conta/creditos'");
      check("Keyboard activates account navigation", true, null);
      const before = log.length;
      for (const [name, path] of routes.slice(1)) {
        await click(
          name === "assinatura"
            ? "Assinatura"
            : name === "creditos"
              ? "Créditos"
              : name === "arquivos"
                ? "Arquivos"
                : "Perfil",
        );
        await waitFor("location.pathname===" + JSON.stringify(path));
        await delay(200);
        const state = await evaluate(
          "({current:document.querySelector('.account-nav [aria-current=page]')?.textContent,focus:document.activeElement?.tagName})",
        );
        check(
          "Client navigation and focus " + name,
          state.focus === "H1",
          state,
        );
      }
      check(
        "No purchase creation during navigation",
        !log
          .slice(before)
          .some(
            (r) => r.method === "POST" && r.path?.endsWith("credit-orders"),
          ),
        log.slice(before),
      );
      for (const [fragment, path] of [
        ["credits-title", "/conta/creditos"],
        ["buy-credits-title", "/conta/creditos"],
        ["plan-title", "/conta/assinatura"],
        ["history-title", "/conta/arquivos"],
        ["profile-title", "/conta/perfil"],
        ["quotation-credits-title", "/conta/creditos"],
        ["purchases-title", "/conta/creditos"],
      ]) {
        await navigate("/conta#" + fragment);
        await waitFor("location.pathname===" + JSON.stringify(path));
        check("Legacy fragment " + fragment, true, path);
      }
      for (const state of process.env.FEITOO_ACCOUNT_QUICK
        ? []
        : [
            "pro-active",
            "scheduled",
            "pro-pending",
            "remote-incomplete",
            "past-due",
            "period-expired",
            "paused",
            "subscription-error",
            "offer-unavailable",
          ]) {
        scenario = state;
        mode = "normal";
        for (const width of [390, 768, 1440]) {
          await viewport(width);
          await navigate("/conta/assinatura");
          await snap("subscription-" + state + "-" + width, width);
        }
      }
      scenario = "pro-pending";
      await navigate("/conta/assinatura");
      await click("Retomar assinatura");
      await delay(100);
      check(
        "Resume calls the existing Pro endpoint",
        log.at(-1).path === "/api/account/subscription/checkout",
        log.at(-1),
      );
      scenario = "past-due";
      await navigate("/conta/assinatura");
      await click("Regularizar pagamento");
      await delay(100);
      check(
        "Payment management calls Portal",
        log.at(-1).path === "/api/account/subscription/portal",
        log.at(-1),
      );
      scenario = "free-zero";
      await viewport(390);
      await navigate("/conta/creditos");
      const start = log.length;
      await click("Comprar 5 créditos");
      await delay(200);
      check(
        "Direct purchase uses allowlisted destination and technical UUID",
        log
          .slice(start)
          .some(
            (r) =>
              r.method === "CHECKOUT" &&
              r.body.return_destination === "account" &&
              /^[0-9a-f-]{36}$/i.test(r.key),
          ),
        log.slice(start),
      );
      scenario = "purchase-pending";
      await navigate(
        "/conta/creditos?quotation_order=" + orderId + "&payment=success",
      );
      await snap("credit-pending-390", 390);
      check(
        "URL success never confirms payment",
        (await evaluate("document.body.innerText")).includes(
          "Pagamento pendente",
        ),
        null,
      );
      mode = "credit-paid";
      await click("Consultar pagamento");
      await delay(200);
      await snap("credit-paid-390", 390);
      check(
        "Backend paid refreshes the visible balance",
        (
          await evaluate("document.querySelector('.balance-value').textContent")
        ).includes("5"),
        null,
      );
      check(
        "Payment return never generates or charges automatically",
        !log
          .slice(start)
          .some(
            (r) =>
              r.path?.includes("/generations") || r.path?.includes("/drafts"),
          ),
        log.slice(start),
      );
      mode = "normal";
      scenario = "many-history";
      await navigate("/conta/arquivos");
      await snap("files-populated-390", 390);
      await click("Excluir arquivos");
      await delay(100);
      check(
        "Delete confirmation focuses confirm action",
        (await evaluate("document.activeElement.textContent.trim()")) ===
          "Confirmar exclusão",
        null,
      );
      await click("Cancelar");
      await delay(100);
      check(
        "Cancel returns focus to delete trigger",
        (await evaluate("document.activeElement.textContent.trim()")) ===
          "Excluir arquivos",
        null,
      );
      await viewport(768);
      await snap("files-populated-768", 768);
      await viewport(1440);
      await snap("files-populated-1440", 1440);
      await navigate("/conta/perfil");
      await evaluate(
        "(() => {const e=document.querySelector('#account-name');e.value='Nome atualizado';e.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('form').requestSubmit();})()",
      );
      await waitFor("document.body.innerText.includes('Nome atualizado.')");
      check(
        "Profile saves through the existing PATCH",
        log.some(
          (r) => r.path === "/api/account/profile" && r.method === "PATCH",
        ),
        null,
      );
      check(
        "Email remains readonly",
        await evaluate("document.querySelector('#account-email').readOnly"),
        null,
      );
      await click("Vincular Google");
      await delay(100);
      check(
        "Google link calls the existing authenticated endpoint",
        log.some(
          (r) => r.path === "/api/auth/google/start" && r.method === "POST",
        ),
        null,
      );
      scenario = "api-error";
      await navigate("/conta/creditos");
      await snap("credit-error-1440", 1440);
      check(
        "Failure does not render false empty purchases",
        !(await evaluate("document.body.innerText")).includes(
          "Você ainda não comprou",
        ),
        null,
      );
      scenario = "loading";
      await navigate("/conta/creditos");
      await snap("credit-loading-1440", 1440);
      scenario = "free-zero";
      mode = "normal";
      await navigate("/conta/assinatura");
      mode = "billing-419";
      await click("Assinar Feitoo Pro");
      await waitFor("location.pathname==='/login'");
      check(
        "Expired commercial session navigates to authentication",
        (await evaluate("location.search")).includes(
          "returnTo=%2Fconta%2Fassinatura",
        ),
        null,
      );
      check("No runtime exceptions", errors.length === 0, errors);
    },
    { blockExternal: true },
  );
} finally {
  process.chdir(originalCwd);
  api.closeAllConnections();
  web.closeAllConnections();
  await Promise.all([
    new Promise((r) => api.close(r)),
    new Promise((r) => web.close(r)),
  ]);
  await writeFile(
    resolve(out, "checks.json"),
    JSON.stringify(
      [...new Map(checks.map((row) => [row.name, row])).values()],
      null,
      2,
    ),
  );
  await writeFile(
    resolve(out, "measurements.json"),
    JSON.stringify(
      [...new Map(measurements.map((row) => [row.name, row])).values()],
      null,
      2,
    ),
  );
  await writeFile(resolve(out, "requests.json"), JSON.stringify(log, null, 2));
}
console.log(
  JSON.stringify({
    checks: checks.length,
    screenshots: measurements.length,
    ok: checks.every((c) => c.ok),
  }),
);
