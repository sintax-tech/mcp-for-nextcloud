<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\App\IAppManager;

/**
 * Knows whether the Workflow OCR app is on and what to tell the model about a PDF or image without text.
 *
 * Workflow OCR writes the recognised text into the PDF as a new version, so {@see TextExtractor} already
 * reads the result; this class never calls the app, it only detects it and words the notice.
 */
class OcrSupport {
    /** App id of Workflow OCR. */
    public const APP = 'workflow_ocr';
    /** Where an administrator gets the app. */
    public const APP_URL = 'https://apps.nextcloud.com/apps/workflow_ocr';

    public function __construct(private IAppManager $appManager) {}

    /** @return bool whether Workflow OCR is installed and enabled */
    public function isActive(): bool {
        return $this->appManager->isEnabledForAnyone(self::APP);
    }

    /**
     * @param string $name file name, whose extension counts
     * @param string $mime MIME type of the file
     * @return bool whether the file is a PDF or a raster image, the kinds that can lack a text layer
     */
    public static function canLackText(string $name, string $mime): bool {
        $mime = strtolower($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $mime === 'application/pdf' || $extension === 'pdf'
            || (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && $extension !== 'svg');
    }

    /** @return string notice for a file without text, depending on whether Workflow OCR is active */
    public function noTextNotice(): string {
        return $this->isActive() ? FilesMessages::noTextOcrActive() : FilesMessages::noTextOcrInactive();
    }
}
