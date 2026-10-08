<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\VisibilityGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;

/**
 * Controller for configuring system tags used to hide sensitive files from MCP tools.
 * Admin-only and CSRF-protected by Nextcloud defaults.
 */
class TagsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private VisibilityGuard $visibilityGuard,
        private ?ISystemTagManager $tagManager = null,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Lists available system tags and the currently configured hidden tag IDs.
     *
     * @return JSONResponse {tags: list<array<string, mixed>>, selected: list<string>}
     */
    public function index(): JSONResponse {
        $selected = $this->visibilityGuard->getHiddenTagIds();
        $tags = [];

        if ($this->tagManager !== null) {
            try {
                $allTags = $this->tagManager->getAllTags(null, false);
                foreach ($allTags as $tag) {
                    if (!$tag instanceof ISystemTag) {
                        continue;
                    }
                    $id = (string)$tag->getId();
                    $visible = $tag->isUserVisible();
                    $assignable = $tag->isUserAssignable();

                    $type = match (true) {
                        !$visible => 'invisible',
                        !$assignable => 'restricted',
                        default => 'collaborative',
                    };

                    $tags[] = [
                        'id' => $id,
                        'name' => $tag->getName(),
                        'userVisible' => $visible,
                        'userAssignable' => $assignable,
                        'type' => $type,
                        'selected' => in_array($id, $selected, true),
                    ];
                }
            } catch (\Throwable) {
                // If tag manager fails, return empty tags list
            }
        }

        return new JSONResponse([
            'tags' => $tags,
            'selected' => $selected,
        ]);
    }

    /**
     * Saves the configured hidden system tag IDs.
     * Body: {tagIds: list<string|int>}
     *
     * @return JSONResponse {selected: list<string>}, or 400
     */
    public function update(): JSONResponse {
        $tagIds = $this->request->getParam('tagIds');
        if (!is_array($tagIds) || !array_is_list($tagIds)) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($tagIds as $id) {
            $str = (string)$id;
            if (!ctype_digit($str) || (int)$str <= 0) {
                return new JSONResponse(['error' => 'Invalid tag ID: ' . $str], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $this->visibilityGuard->setHiddenTagIds($tagIds);
            return new JSONResponse([
                'selected' => $this->visibilityGuard->getHiddenTagIds(),
            ]);
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }
}
