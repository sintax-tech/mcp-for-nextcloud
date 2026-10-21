<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/PhotoCache.php
// SHA-256: c2ced10d16949bf86d0f97a5b2c7732cd50e71300f1de654031d62d201e822ff

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\CardDAV;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Image;
use Psr\Log\LoggerInterface;
use Sabre\CardDAV\Card;
use Sabre\VObject\Document;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property\Binary;
use Sabre\VObject\Reader;
class PhotoCache
{
    private ?IAppData $photoCacheAppData = null;
    /** @var array */
    public const ALLOWED_CONTENT_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/vnd.microsoft.icon' => 'ico', 'image/webp' => 'webp', 'image/avif' => 'avif'];
    public function __construct(private IAppDataFactory $appDataFactory, private LoggerInterface $logger)
    {
    }
    /**
     * @throws NotFoundException
     */
    public function get(int $addressBookId, string $cardUri, int $size, Card $card): ISimpleFile
    {
    }
    /**
     * @return false|array{body: string, Content-Type: string}
     */
    public function getPhotoFromVObject(Document $vObject)
    {
    }
    /**
     * @param int $addressBookId
     * @param string $cardUri
     * @throws NotPermittedException
     */
    public function delete($addressBookId, $cardUri)
    {
    }
}
