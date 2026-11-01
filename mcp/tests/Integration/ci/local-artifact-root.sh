#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Prints the checkout-owned artifact root, outside the app and its recursive source readers.
set -eu
repo=$(cd "$(dirname "$0")/../../../.." && pwd)
printf '%s/.mcp-it-runs\n' "$repo"
