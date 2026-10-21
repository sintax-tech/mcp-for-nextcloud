<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

/** Supported protocol versions, wire keys and server identity. */
final class ProtocolMetadata {
    /** Legacy version answered in initialize. */
    public const VERSION = '2025-06-18';
    /** Modern (stateless) protocol version. */
    public const MODERN_VERSION = '2026-07-28';
    /** Built-in diagnostic tool that reads no user data. */
    public const TOOL = 'mcp_status';
    /** MCP-Protocol-Version values accepted after a legacy initialize. */
    public const KNOWN_VERSIONS = ['2025-03-26', '2025-06-18', '2025-11-25'];
    /** Versions advertised by server/discover and UnsupportedProtocolVersionError, newest first. */
    public const SUPPORTED_VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
    /** Version assumed when the header is absent, as the Streamable HTTP transport specifies. */
    public const DEFAULT_HEADER_VERSION = '2025-03-26';
    /** _meta key of the version a modern request uses. */
    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    /** _meta key of the server identity in modern results. */
    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    /** Server identity reported in initialize and modern results. */
    public const SERVER_INFO = ['name' => 'mcp-for-cloud', 'version' => '1.1.2'];
    /** HeaderMismatch error code of the 2026-07-28 specification. */
    public const HEADER_MISMATCH = -32020;
    /** UnsupportedProtocolVersion error code of the 2026-07-28 specification. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;
}
