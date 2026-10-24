<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mail\Exception;

/**
 * Test-only shape of the exception Nextcloud Mail 5.x throws from AccountService::find() for an account the user does
 * not own. It is loaded explicitly by the tests that need it, never by the autoloader.
 */
class ClientException extends \Exception {
}
