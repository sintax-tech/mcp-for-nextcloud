// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from "node:assert/strict";
import { isDeepStrictEqual } from "node:util";
const normalize = (value) =>
  value && typeof value === "object"
    ? Object.fromEntries(
        Object.entries(value).map(([key, item]) => [key, normalize(item)]),
      )
    : value;
export function canRestoreConfig(before, current, changes) {
  const original = normalize(before),
    remaining = normalize(current);
  for (const { key, index, value } of changes) {
    const old = original[key]?.[index],
      now = remaining[key]?.[index];
    if (!isDeepStrictEqual(now, old) && !isDeepStrictEqual(now, value))
      return false;
    if (Object.hasOwn(original[key] ?? {}, index)) {
      remaining[key] ??= {};
      remaining[key][index] = old;
    } else if (remaining[key]) delete remaining[key][index];
    if (
      !Object.hasOwn(original, key) &&
      remaining[key] &&
      Object.keys(remaining[key]).length === 0
    )
      delete remaining[key];
  }
  return isDeepStrictEqual(original, remaining);
}
export function reconcileNamedToken(runtime, name) {
  const list = () =>
    JSON.parse(
      runtime.occ("user:auth-tokens:list", "ui-firefox", "--output=json"),
    );
  for (const token of list().filter((t) => t.name === name)) {
    assert.match(String(token.id), /^\d+$/);
    runtime.occ("user:auth-tokens:delete", "ui-firefox", String(token.id));
  }
  assert.equal(
    list().some((t) => t.name === name),
    false,
  );
}
