#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Replay focused Polls mutations sequentially and preserve the exact diff and command."""

import difflib
import hashlib
import json
import pathlib
import subprocess
import sys


class PollsMutationProof:
	"""One mutation at a time, with unconditional byte restoration and assertion-only kills."""

	def __init__(self, root: pathlib.Path, output: pathlib.Path):
		"""Receive the explicit repository root and evidence destination."""
		self.root = root.resolve()
		self.output = output.resolve()
		self.output.mkdir(parents=True, exist_ok=True)

	def run(self) -> bool:
		"""Record every mutation and return true only if all tests kill by assertion."""
		definitions = json.loads(pathlib.Path(__file__).with_name("mutations.json").read_text())
		records = []
		for mutation in definitions:
			records.append(self.prove(mutation))
		(self.output / "manifest.json").write_text(json.dumps(records, indent="\t") + "\n")
		return all(record["killedByAssertion"] and record["restored"] for record in records)

	def prove(self, mutation: dict) -> dict:
		"""Apply a unique source replacement, lint, run its guard assertion and restore bytes."""
		path = self.root / mutation["path"]
		original = path.read_bytes()
		source = original.decode()
		if source.count(mutation["old"]) != 1:
			raise ValueError("Mutation no longer has exactly one target: " + mutation["name"])
		changed = source.replace(mutation["old"], mutation["new"])
		name = mutation["name"]
		patch = "".join(difflib.unified_diff(
			source.splitlines(True), changed.splitlines(True),
			fromfile=mutation["path"], tofile=mutation["path"],
		))
		(self.output / (name + ".patch")).write_text(patch)
		command = ["php", "-d", "error_reporting=22527", "mcp/vendor/bin/phpunit", "-c",
			"mcp/phpunit.xml", "mcp/tests/Unit/Modules/Polls", "--filter", mutation["filter"]]
		record = dict(mutation, diff=name + ".patch", command=command,
			originalSha256=hashlib.sha256(original).hexdigest(),
			mutatedSha256=hashlib.sha256(changed.encode()).hexdigest())
		try:
			path.write_text(changed)
			lint = subprocess.run(["php", "-l", str(path)], cwd=self.root, capture_output=True, text=True)
			record["lintExit"] = lint.returncode
			if lint.returncode:
				raise ValueError("Invalid mutation, not a guard proof: " + name + "\n" + lint.stderr)
			test = subprocess.run(command, cwd=self.root, capture_output=True, text=True)
			log = test.stdout + test.stderr
			(self.output / (name + ".log")).write_text(log)
			record["exit"] = test.returncode
			record["log"] = name + ".log"
			record["killedByAssertion"] = test.returncode == 1 and "FAILURES!" in log and "Errors:" not in log
		finally:
			path.write_bytes(original)
			record["restored"] = path.read_bytes() == original
		print(name + ": " + ("KILLED" if record["killedByAssertion"] else "SURVIVED/INVALID"), flush=True)
		return record


if __name__ == "__main__":
	if len(sys.argv) != 3:
		raise SystemExit("Usage: run.py <repository-root> <evidence-directory>")
	raise SystemExit(0 if PollsMutationProof(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])).run() else 1)
