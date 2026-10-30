<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\ClientMetadataDownloader;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/consent-template-functions.php';

/**
 * The local authorization error page shows the metadata timeout in the user's language, while the fixed protocol
 * description (also the value of the failure cache) stays the English text.
 */
final class ConsentTimeoutPageTest extends TestCase {
	/**
	 * @param string $error the fixed description of the OAuthException shown on the page
	 * @param string $language catalog of the reader
	 * @return string HTML of templates/consent.php for that error
	 */
	private static function render(string $error, string $language): string {
		$l = new JsonL10n($language);
		$_ = ['error' => $error, 'blocked' => false, 'unavailable' => false, 'pending' => null];
		ob_start();
		include __DIR__ . '/../../../templates/consent.php';
		return (string)ob_get_clean();
	}

	/** The timeout reaches a Portuguese reader in Portuguese, not as the English protocol text. */
	public function testTheTimeoutIsShownTranslated(): void {
		$html = self::render(ClientMetadataDownloader::TIMEOUT_MESSAGE, 'pt_BR');

		$this->assertStringContainsString('O cliente não enviou o documento de metadados a tempo. Tente conectar de novo em instantes.', $html);
		$this->assertStringContainsString('A solicitação de autorização é inválida.', $html);
		$this->assertStringNotContainsString(ClientMetadataDownloader::TIMEOUT_MESSAGE, $html);
	}

	/** German and Spanish readers get their own catalog entry too. */
	public function testEveryCatalogTranslatesTheTimeout(): void {
		foreach (['de', 'es'] as $language) {
			$this->assertStringNotContainsString(ClientMetadataDownloader::TIMEOUT_MESSAGE,
				self::render(ClientMetadataDownloader::TIMEOUT_MESSAGE, $language), $language);
		}
	}

	/** Other descriptions keep today's presentation: printed as they are. */
	public function testOtherErrorsAreShownAsBefore(): void {
		$html = self::render(ClientMetadataDownloader::UNAVAILABLE_MESSAGE, 'pt_BR');

		$this->assertStringContainsString(ClientMetadataDownloader::UNAVAILABLE_MESSAGE, $html);
	}
}
