<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

/**
 * The ceilings a recursive copy answers to.
 *
 * They live in their own class because they are a promise about work, not about files: a copy walks the
 * source first to count nodes and bytes, and the ceiling is what keeps that walk and the copy itself from
 * turning into a long request nobody asked for. The byte ceiling applies across storages too, because a
 * recursive copy costs about the same time either way.
 */
final class ReorganizationLimits {
    /** Most nodes a copy may bring with it. */
    public const NODES = 2000;
    /** Most bytes a copy may bring with it: 1 GiB. */
    public const BYTES = 1073741824;
    /** Most moves in one batch, and most folders it may create. A plan nobody can read is not a plan. */
    public const BATCH_ITEMS = 200;
}
