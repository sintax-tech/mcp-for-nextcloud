// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import net from "node:net";
import assert from "node:assert/strict";
import { command, save, hash } from "./support.mjs";
import { canRestoreConfig } from "./cleanup.mjs";
export class Runtime {
  constructor(fixture, state) {
    this.fixture = fixture;
    this.state = state;
    this.run = JSON.parse(fs.readFileSync(path.join(fixture, "run.json")));
    this.started = [];
    this.configChanges = [];
    this.ports = [18080, 18443, 6276];
    this.timer = "mcp-private-tls-" + path.basename(state);
  }
  docker(...args) {
    return command("docker", args);
  }
  occ(...args) {
    return command("docker", [
      "exec",
      "-u",
      "www-data",
      this.run.nc,
      "php",
      "occ",
      ...args,
    ]);
  }
  inspect(name) {
    return JSON.parse(this.docker("inspect", name))[0];
  }
  async prepare() {
    assert.equal(
      this.run.candidate,
      "b96884631bf9bda245ce9895facfa8380ab08998",
    );
    assert.equal(this.run.origin, "http://127.0.0.1:18080");
    for (const name of [this.run.nc, this.run.db]) {
      const info = this.inspect(name);
      assert.equal(info.Config.Labels["mcp.ui.run"], this.run.run_id);
      assert.equal(info.State.Running, false);
      assert.equal(info.HostConfig.RestartPolicy.Name, "no");
      assert.deepEqual(
        info.HostConfig.PortBindings,
        name === this.run.nc
          ? { "80/tcp": [{ HostIp: "127.0.0.1", HostPort: "18080" }] }
          : {},
      );
    }
    assert.equal(
      JSON.parse(this.docker("network", "inspect", this.run.network))[0]
        .Internal,
      true,
    );
    for (const port of this.ports)
      await new Promise((resolve, reject) => {
        const s = net.createServer();
        s.once("error", reject);
        s.listen(port, "127.0.0.1", () => s.close(resolve));
      });
    fs.writeFileSync(
      path.join(this.fixture, "p1.lock"),
      JSON.stringify({ pid: process.pid, run: path.basename(this.state) }),
      {
        flag: "wx",
        mode: 0o600,
      },
    );
    this.locked = true;
    const watchdog = path.join(this.state, "watchdog.py");
    fs.writeFileSync(
      watchdog,
      `import json,subprocess,pathlib\nlock=pathlib.Path(${JSON.stringify(path.join(this.fixture, "p1.lock"))})\nif not lock.exists() or json.loads(lock.read_text()).get("run")!=${JSON.stringify(path.basename(this.state))}: raise SystemExit(0)\nnames=${JSON.stringify([this.run.nc, this.run.db])}\nfor name in names:\n info=json.loads(subprocess.check_output(['docker','inspect',name]))[0]\n if info['Config']['Labels'].get('mcp.ui.run')!=${JSON.stringify(this.run.run_id)}: raise RuntimeError('ownership mismatch')\n if info['State']['Running']:\n  if name==${JSON.stringify(this.run.nc)}: subprocess.run(['docker','exec','-u','www-data',name,'php','occ','config:app:set','mcp','service_enabled','--value=0'],stdout=subprocess.DEVNULL)\n  subprocess.run(['docker','stop',name],check=True,stdout=subprocess.DEVNULL)\n`,
      { mode: 0o600 },
    );
    this.systemd("systemd-run", [
      "--user",
      "--unit=" + this.timer,
      "--on-active=25m",
      "--timer-property=AccuracySec=1s",
      "/usr/bin/python3",
      watchdog,
    ]);
    this.timerArmed = true;
    for (const name of [this.run.db, this.run.nc]) {
      this.docker("start", name);
      this.started.push(name);
    }
    const address = this.inspect(this.run.nc).NetworkSettings.Networks[
      this.run.network
    ].IPAddress;
    this.relay = net.createServer((client) => {
      const remote = net.connect(80, address);
      client.pipe(remote).pipe(client);
      remote.on("error", () => client.destroy());
      client.on("error", () => remote.destroy());
      client.on("close", () => remote.destroy());
    });
    await new Promise((resolve, reject) => {
      this.relay.once("error", reject);
      this.relay.listen(18080, "127.0.0.1", resolve);
    });
    for (let i = 0; i < 30; i++) {
      try {
        const r = await fetch(this.run.origin + "/status.php", {
          signal: AbortSignal.timeout(2000),
        });
        if ((await r.json()).installed) break;
      } catch {}
      await new Promise((r) => setTimeout(r, 1000));
    }
    this.originalConfig = this.docker(
      "exec",
      this.run.nc,
      "cat",
      "config/config.php",
    );
    this.expectedConfig = this.originalConfig;
    fs.writeFileSync(
      path.join(this.state, "config-before.private.php"),
      this.originalConfig,
      { mode: 0o600 },
    );
    assert.equal(
      this.occ("config:app:get", "mcp", "service_enabled").trim(),
      "0",
    );
    this.originalFiles = this.files();
    const expected = JSON.parse(
      fs.readFileSync(path.join(this.fixture, "installed-files.json")),
    );
    assert.deepEqual(this.originalFiles, expected);
    this.originalConfigValues = this.configValues();
    this.configChanges.push({
      key: "trusted_domains",
      index: 9,
      value: "mcp-p1.test:18443",
    });
    this.occ(
      "config:system:set",
      "trusted_domains",
      "9",
      "--value=mcp-p1.test:18443",
    );
    this.expectedConfig = this.docker(
      "exec",
      this.run.nc,
      "cat",
      "config/config.php",
    );
    // The relay crosses the Docker bridge: bind overwrite behavior to that exact observed peer.
    const gateway = JSON.parse(
      this.docker("network", "inspect", this.run.network),
    )[0].IPAM.Config[0].Gateway;
    assert(net.isIP(gateway));
    this.configChanges.push({
      key: "trusted_proxies",
      index: 9,
      value: gateway,
    });
    this.occ("config:system:set", "trusted_proxies", "9", "--value=" + gateway);
    this.expectedConfig = this.docker(
      "exec",
      this.run.nc,
      "cat",
      "config/config.php",
    );
    save(path.join(this.state, "runtime.json"), {
      candidate: this.run.candidate,
      node: process.version,
      networkInternal: true,
      ports: this.ports,
      configBefore: hash(this.originalConfig),
      images: [
        this.inspect(this.run.nc).Image,
        this.inspect(this.run.db).Image,
      ],
    });
  }
  systemd(file, args) {
    return command("env", [
      "XDG_RUNTIME_DIR=/run/user/" + process.getuid(),
      "DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/" +
        process.getuid() +
        "/bus",
      file,
      ...args,
    ]);
  }
  configValues() {
    return JSON.parse(
      command(
        "docker",
        ["exec", "-i", this.run.nc, "php"],
        "<?php require 'config/config.php'; echo json_encode($CONFIG);",
      ),
    );
  }
  files() {
    return JSON.parse(
      command(
        "docker",
        ["exec", "-i", this.run.nc, "php"],
        `<?php $base='/var/www/html/custom_apps/mcp';$out=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())$out['mcp/'.substr($f->getPathname(),strlen($base)+1)]=hash_file('sha256',$f->getPathname());}ksort($out);echo json_encode($out);`,
      ),
    );
  }
  restoreConfig() {
    command(
      "docker",
      [
        "exec",
        "-i",
        "-u",
        "www-data",
        this.run.nc,
        "sh",
        "-c",
        "cat > config/config.php",
      ],
      this.originalConfig,
    );
  }
  async close() {
    const errors = [];
    let unchanged = false;
    if (this.started.includes(this.run.nc)) {
      try {
        this.occ("config:app:set", "mcp", "service_enabled", "--value=0");
      } catch {
        errors.push("service-disable");
      }
      try {
        assert.deepEqual(this.files(), this.originalFiles);
        unchanged = true;
      } catch {
        errors.push("installed-files");
      }
      if (this.originalConfig)
        try {
          // Refuse source-level changes too, including comments outside $CONFIG.
          // An uncertain write leaves the lock for recovery rather than guessing.
          assert.equal(
            this.docker("exec", this.run.nc, "cat", "config/config.php"),
            this.expectedConfig,
          );
          if (this.originalConfigValues)
            assert(
              canRestoreConfig(
                this.originalConfigValues,
                this.configValues(),
                this.configChanges,
              ),
            );
          else
            assert.equal(
              this.docker("exec", this.run.nc, "cat", "config/config.php"),
              this.originalConfig,
            );
          this.restoreConfig();
          assert.equal(
            this.docker("exec", this.run.nc, "cat", "config/config.php"),
            this.originalConfig,
          );
        } catch {
          errors.push("config-restore-conflict");
        }
    }
    this.relay?.close();
    for (const name of [...this.started].reverse())
      try {
        assert.equal(
          this.inspect(name).Config.Labels["mcp.ui.run"],
          this.run.run_id,
        );
        this.docker("stop", name);
        assert.equal(this.inspect(name).State.Running, false);
      } catch {
        errors.push("container-stop");
      }
    for (const port of this.ports)
      try {
        await new Promise((resolve, reject) => {
          const s = net.createServer();
          s.once("error", reject);
          s.listen(port, "127.0.0.1", () => s.close(resolve));
        });
      } catch {
        errors.push("port-" + port);
      }
    if (errors.length === 0 && this.timerArmed)
      this.systemd("systemctl", ["--user", "stop", this.timer + ".timer"]);
    if (this.locked && errors.length === 0)
      fs.unlinkSync(path.join(this.fixture, "p1.lock"));
    return {
      status: errors.length ? "failed" : "passed",
      errors,
      installedFilesUnchanged: unchanged,
      publicExposure: false,
      egressAdded: false,
      portsClosed: errors.every((e) => !e.startsWith("port-")),
    };
  }
}
