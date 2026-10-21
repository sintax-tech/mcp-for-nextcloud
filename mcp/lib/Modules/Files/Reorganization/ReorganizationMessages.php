<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\L10n\Translator;

final class ReorganizationMessages {
    /** @return string something already sits where a folder would go */
    public static function destinationExists(): string {
        return Translator::t('A file or folder already exists at this destination.');
    }

    /**
     * @return string the plan of files_mkdir
     */
    public static function planMkdir(): string {
        return Translator::t('Nothing was changed. After your approval the folders below are created.');
    }

    /**
     * @param int $conflicts how many items clash with what is already there
     * @param int $denied how many items Nextcloud will not let the user touch
     * @return string a batch whose plan still has blocked items
     */
    public static function batchNotOk(int $conflicts, int $denied): string {
        return Translator::t('The plan has %s conflict(s) and %s item(s) without permission; call it again without confirm, fix the list and try again. Nothing was moved.', [(string)$conflicts, (string)$denied]);
    }

    /** @return string an unexpected failure while moving one item of a batch */
    public static function moveFailed(): string {
        return Translator::t('Failed to move the item.');
    }

    /**
     * @return string the plan of files_move_batch
     */
    public static function planBatch(): string {
        return Translator::t('Nothing was changed. After your approval the items below are moved in the order shown and the folders are created. The run returns a batch_id that undoes it.');
    }

    /**
     * @return string the plan of files_copy
     */
    public static function planCopy(): string {
        return Translator::t('Nothing was changed. After your approval the item below is copied to the destination, as a new independent copy.');
    }

    /**
     * @return string the plan of files_move
     */
    public static function planMove(): string {
        return Translator::t('Nothing was changed. After your approval the item below moves to the destination, keeping its id, its versions and its shares.');
    }

    /** @return string a batch that does not exist, is not this user's, or is past its lifetime */
    public static function batchNotFound(): string {
        return Translator::t('Batch not found.');
    }

    /** @return string a batch that was already undone */
    public static function batchAlreadyUndone(): string {
        return Translator::t('This batch has already been undone.');
    }

    /**
     * @return string the plan of files_undo_batch
     */
    public static function planUndoBatch(): string {
        return Translator::t('Nothing was changed. After your approval each item below goes back to where the batch took it from, and only the empty folders the batch created are removed.');
    }

    /** @return string a node at the destination that is not the one the batch moved */
    public static function notTheBatchNode(): string {
        return Translator::t('The item is no longer what this batch moved; nothing was undone.');
    }

    /** @return string a batch whose undo would touch an item hidden from the MCP tools, without naming it */
    public static function batchHasHiddenItem(): string {
        return Translator::t('This batch can no longer be undone because one of its items is not available to the MCP tools. Nothing was undone.');
    }

    /**
     * @param int $limit ceiling in nodes
     * @return string a copy whose source is larger than the node ceiling
     */
    public static function copyTooManyNodes(int $limit): string {
        return Translator::t('The copy would exceed %s items; nothing was copied. Choose a smaller folder.', [(string)$limit]);
    }

    /**
     * @param int $limit ceiling in bytes
     * @return string a copy whose source is larger than the byte ceiling
     */
    public static function copyTooLarge(int $limit): string {
        return Translator::t('The copy would exceed %s bytes; nothing was copied. Choose a smaller folder.', [(string)$limit]);
    }

    /** @return string a folder moved into itself or into one of its own subfolders */
    public static function folderIntoItself(): string {
        return Translator::t('Cannot move a folder into itself.');
    }

    /** @return string something already sits where a folder would go */
    public static function crossStorage(): string {
        return Translator::t('Moving across different storages is not supported in this version; move within the same file area.');
    }

    /** @return string description of files_mkdir */
    public static function mkdirTool(): string {
        return 'Create a folder, including intermediate levels when missing. Refuses if destination '
            . 'already exists and never deletes existing contents. If destination is outside your personal '
            . 'folder, ask the user first and retry with confirm_shared.';
    }

    /** @return string description of files_copy */
    public static function copyTool(): string {
        return 'Copy a file or folder to another path, creating a new node with a new id. Never '
            . 'overwrites the destination. The copy is an independent node: does not preserve source id, versions, '
            . 'or shares. Capped at ' . ReorganizationLimits::NODES . ' items and '
            . ReorganizationLimits::BYTES . ' bytes, measured before copying. If source or destination '
            . 'is outside your personal folder, ask the user first and retry with confirm_shared.';
    }

    /** @return string description of files_move */
    public static function moveTool(): string {
        return 'Move or rename a file or folder within the same file area, without overwriting the '
            . 'destination. Reports id before and after, as well as version and share counts when '
            . 'corresponding apps are enabled. Does not move across different storages: there Nextcloud treats '
            . 'it as a copy and the id changes. If source or destination is outside your personal folder, ask '
            . 'the user first and retry with confirm_shared.';
    }

    /** @return string description of files_move_batch */
    public static function batchTool(): string {
        return 'Move several files and create folders, in the given order. Without confirm: true writes nothing '
            . 'and returns the full plan: what would succeed, what conflicts, what Nextcloud denies, what belongs '
            . 'to someone else, and which folders would be created. With confirm: true it executes in order, '
            . 'creates the folders beforehand and returns a batch_id to undo with files_undo_batch. An item '
            . 'failing midway halts the batch: items already moved remain recorded and can be undone, and '
            . 'unattempted items are returned as not_attempted.';
    }

    /** @return string description of files_undo_batch */
    public static function undoTool(): string {
        return 'Undo a files_move_batch, returning each node to its source path in reverse order. '
            . 'Before making any changes checks the whole batch: if any item is no longer what the '
            . 'batch placed at destination, if the source path is already occupied, or if permissions changed, '
            . 'nothing is undone and the response identifies the blocking item. Removes only empty folders that the '
            . 'batch itself created; a folder with content is preserved and listed in kept_dirs.';
    }
}
