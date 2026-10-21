// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { chromium, firefox } from "playwright";
import { expect } from "@playwright/test";
export class Browser {
  constructor(secret, state, origin) {
    this.secret = secret;
    this.state = state;
    this.origin = origin;
    this.clients = [];
    this.clientNames = [];
  }
  async start() {
    this.adminBrowser = await chromium.launch();
    this.admin = await this.adminBrowser.newPage();
    await this.login(
      this.admin,
      "http://127.0.0.1:18080",
      "ui-admin",
      this.secret.admin,
    );
    this.userBrowser = await firefox.launchPersistentContext(
      this.state + "/firefox",
      { firefoxUserPrefs: { "network.dns.localDomains": "mcp-p1.test" } },
    );
    this.user = await this.userBrowser.newPage();
  }
  async login(page, origin, user, password) {
    await page.goto(origin + "/login");
    await page.locator("#user").fill(user);
    await page.locator("#password").fill(password);
    await page.locator("button[type=submit]").click();
    try {
      await page.waitForURL((u) => !u.pathname.includes("/login"), {
        waitUntil: "domcontentloaded",
        timeout: 20000,
      });
    } catch {
      throw new Error(
        "Login failed: " +
          new URL(page.url()).pathname +
          " " +
          (await page.locator("body").innerText()).slice(-1500),
      );
    }
  }
  async setup() {
    const p = this.admin;
    await p.goto("http://127.0.0.1:18080/settings/admin/mcp");
    await expect(p.locator("#mcp-service")).toBeEnabled();
    this.initialService = await p.locator("#mcp-service").isChecked();
    assertFalse(this.initialService);
    await p.locator("label[for=mcp-service]").click();
    await expect(p.locator("#mcp-service-status")).toHaveText("Saved");
    await p.locator("#mcp-tab-access").click();
    await p.locator("#mcp-view-operations").click();
    await p.locator("#mcp-filter").selectOption("");
    this.changed = [];
    for (const label of [
      "ui-firefox — Can connect",
      "ui-firefox — Files: read",
      "ui-firefox — Files: edit",
    ]) {
      const box = p.locator('input[aria-label^="' + label + '"]');
      if (!(await box.isChecked())) {
        this.changed.push(label);
        await box.check();
        await expect(box).toBeEnabled();
      }
    }
    this.personal = await this.adminBrowser.newPage();
    await this.login(
      this.personal,
      "http://127.0.0.1:18080",
      "ui-firefox",
      this.secret.alice,
    );
    await this.connect();
  }
  async connect() {
    await this.personal.goto("http://127.0.0.1:18080/settings/user/mcp");
    const c = this.personal.getByRole("button", {
      name: "Connect",
      exact: true,
    });
    if (await c.count()) await c.click();
    await expect(
      this.personal.getByRole("button", { name: "Disconnect", exact: true }),
    ).toBeVisible();
  }
  async disconnect() {
    await this.personal.goto("http://127.0.0.1:18080/settings/user/mcp");
    await this.personal
      .getByRole("button", { name: "Disconnect", exact: true })
      .click();
    await expect(
      this.personal.getByRole("button", { name: "Connect", exact: true }),
    ).toBeVisible();
  }
  async addClient(name) {
    const p = this.admin;
    await p.goto("http://127.0.0.1:18080/settings/admin/mcp#clients");
    await p.reload();
    await p.locator("#mcp-custom-add").click();
    const card = p.locator(".mcp-custom-client").last();
    await card.getByLabel("Name", { exact: true }).fill(name);
    await card
      .getByLabel("Accepted redirect URIs", { exact: true })
      .fill("http://127.0.0.1:6276/oauth/callback");
    this.clientNames.push(name);
    await card.getByRole("button", { name: "Save", exact: true }).click();
    await expect(card.locator(".mcp-status")).toHaveText("Saved");
    const id = await card.locator("code").textContent();
    this.clients.push(id);
    return id;
  }
  async changeClient(id, action) {
    const p = this.admin;
    await p.goto("http://127.0.0.1:18080/settings/admin/mcp#clients");
    await p.reload();
    const card = p.locator(".mcp-custom-client").filter({ hasText: id });
    await expect(card).toHaveCount(1);
    if (action === "delete") {
      p.once("dialog", (d) => d.accept());
      await card.getByRole("button", { name: "Delete", exact: true }).click();
      await expect(card).toHaveCount(0);
      this.clients = this.clients.filter((x) => x !== id);
      return;
    }
    if (action === "redirect") {
      await card
        .getByLabel("Accepted redirect URIs", { exact: true })
        .fill("http://127.0.0.1:6277/oauth/callback");
    } else if (action === "restore") {
      await card
        .getByLabel("Accepted redirect URIs", { exact: true })
        .fill("http://127.0.0.1:6276/oauth/callback");
    } else {
      const box = card.getByRole("checkbox");
      if ((await box.isChecked()) !== (action === "enable")) {
        const id = await box.getAttribute("id");
        await card.locator('label[for="' + id + '"]').click();
      }
      await expect(box).toBeChecked({ checked: action === "enable" });
    }
    await card.getByRole("button", { name: "Save", exact: true }).click();
    await expect(card.locator(".mcp-status")).toHaveText("Saved");
  }
  async close() {
    const failures = [];
    if (this.personal)
      try {
        await this.disconnect();
      } catch {
        failures.push("personal-disconnect");
      }
    if (this.clientNames.length)
      try {
        const p = this.admin;
        await p.goto("http://127.0.0.1:18080/settings/admin/mcp#clients");
        await p.reload();
        for (const card of await p.locator(".mcp-custom-client").all()) {
          if (
            this.clientNames.includes(
              await card.getByLabel("Name", { exact: true }).inputValue(),
            )
          ) {
            const id = await card.locator("code").textContent();
            if (!this.clients.includes(id)) this.clients.push(id);
          }
        }
      } catch {
        failures.push("client-reconcile");
      }
    for (const id of [...this.clients])
      try {
        await this.changeClient(id, "delete");
      } catch {
        failures.push("client-delete");
      }
    if (this.changed?.length)
      try {
        const p = this.admin;
        await p.goto("http://127.0.0.1:18080/settings/admin/mcp#access");
        await p.reload();
        await p.locator("#mcp-view-operations").click();
        await p.locator("#mcp-filter").selectOption("");
        for (const label of this.changed) {
          const box = p.locator('input[aria-label^="' + label + '"]');
          await box.uncheck();
          await expect(box).toBeEnabled();
        }
      } catch {
        failures.push("grant-restore");
      }
    await this.userBrowser?.close();
    await this.adminBrowser?.close();
    return failures;
  }
}
function assertFalse(value) {
  if (value) throw new Error("Fixture service must start disabled");
}
