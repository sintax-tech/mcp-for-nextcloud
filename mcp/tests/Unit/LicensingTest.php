<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicensingTest extends TestCase {
    /** Every library source must declare its copyright and license in its PHP header. */
    public function testEveryLibraryFileHasSpdxHeader(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib', \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (!preg_match('~\A<\?php\s*/\*\s*\* SPDX-FileCopyrightText: 2026 Sintax \(Jhonatan Jaworski\) <contato@sintax\.tech>\s*\* SPDX-License-Identifier: AGPL-3\.0-or-later\s*\*/~', $source)) {
                $missing[] = $file->getPathname();
            }
        }
        $this->assertSame([], $missing, 'Library sources without the SPDX header');
    }
}
