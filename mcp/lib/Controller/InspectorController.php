<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Inspection\AccessInspector;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Routes of the effective access inspector (ticket 13; issue #4 A5, B6). Admin-only and CSRF-checked by the framework
 * defaults, with an explicit administrator guard that answers 403 before the target is resolved in any way. Every
 * question an administrator asks is audited through the app's logger with who asked, about whom, what and the
 * answer's code, never a path, a calendar, a title or any other name.
 */
class InspectorController extends Controller {
    public function __construct(string $appName, IRequest $request, private AccessInspector $inspector,
        private IUserSession $session, private IGroupManager $groups, private LoggerInterface $logger,
        private ITimeFactory $time) {
        parent::__construct($appName, $request);
    }

    /** GET /api/inspector/access?uid=: the effective access of every catalog operation. */
    public function access(string $uid = ''): JSONResponse {
        return $this->ask('inspect_access', $uid, null, fn (): array => $this->inspector->effective($uid),
            static fn (array $answer): string => 'operations:' . count($answer['operations']));
    }

    /** GET /api/inspector/path?uid=&path=: why the Files tools can or cannot read one path. */
    public function path(string $uid = '', string $path = ''): JSONResponse {
        return $this->ask('inspect_path', $uid, 'files', fn (): array => $this->inspector->path($uid, $path),
            static fn (array $answer): string => self::code($answer));
    }

    /** GET /api/inspector/calendar?uid=&module=&calendarId=&operation=: one calendar (VEVENT) or task list (VTODO). */
    public function calendar(string $uid = '', string $module = '', string $calendarId = '', string $operation = 'read'): JSONResponse {
        return $this->ask('inspect_calendar', $uid, $module, fn (): array => $this->inspector->calendar($uid, $module, $calendarId, $operation),
            static fn (array $answer): string => self::code($answer));
    }

    /**
     * @param callable(): array<string, mixed> $read the question, asked only after the administrator check
     * @param callable(array<string, mixed>): string $result the code the audit records
     */
    private function ask(string $action, string $uid, ?string $module, callable $read, callable $result): JSONResponse {
        $admin = $this->session->getUser();
        if ($admin === null || !$this->groups->isAdmin($admin->getUID())) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        if ($uid === '' || str_contains($uid, "\0")) {
            return new JSONResponse(['error' => 'Invalid request'], 400);
        }
        try {
            $answer = $read();
            $outcome = $result($answer);
            $response = new JSONResponse($answer);
        } catch (\InvalidArgumentException) {
            $outcome = 'invalid';
            $response = new JSONResponse(['error' => 'Invalid request'], 400);
        } catch (PolicyUnavailable) {
            $outcome = 'unavailable';
            $response = new JSONResponse(['error' => 'Unavailable'], 503);
        } catch (\Throwable $e) {
            // Audited before the generic 500, with the class of the failure only: never its message, a path or a name.
            $outcome = 'error';
            $response = new JSONResponse(['error' => 'Unexpected error'], 500);
        }
        $context = ['app' => 'mcp', 'admin' => $admin->getUID(), 'target' => $uid,
            'action' => $action, 'module' => $module, 'result' => $outcome, 'timestamp' => $this->time->getTime()];
        if ($outcome === 'error') {
            $this->logger->error('MCP access inspection failed', $context + ['exception_class' => $e::class]);
        } else {
            $this->logger->info('MCP access inspection', $context);
        }
        return $response;
    }

    /** @param array<string, mixed> $answer */
    private static function code(array $answer): string {
        return implode(':', array_filter([$answer['reason'] ?? null, $answer['gate'] ?? null, $answer['exclusion'] ?? null], 'is_string'));
    }
}
