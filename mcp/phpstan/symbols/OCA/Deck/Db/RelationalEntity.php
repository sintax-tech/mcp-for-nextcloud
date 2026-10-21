<?php
/*
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/RelationalEntity.php
// SHA-256: 18c3c141973d1226aed7b1996305a7f3dfbd02684fafc054751e131db385cb24

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use OCP\AppFramework\Db\Entity;
class RelationalEntity extends Entity implements \JsonSerializable
{
    private $_relations = [];
    private $_resolvedProperties = [];
    /**
     * Mark a property as relation so it will not get updated using Mapper::update
     * @param string $property string Name of the property
     */
    public function addRelation($property)
    {
    }
    /**
     * Mark a property as resolvable via resolveRelation()
     * @param string $property string Name of the property
     */
    public function addResolvable($property)
    {
    }
    /**
     * Mark am attribute as updated
     * overwritten from \OCP\AppFramework\Db\Entity to avoid writing relational attributes
     * @param string $attribute the name of the attribute
     * @since 7.0.0
     */
    protected function markFieldUpdated(string $attribute): void
    {
    }
    /**
     * @return array serialized data
     * @throws \ReflectionException
     */
    public function jsonSerialize(): array
    {
    }
    public function __toString(): string
    {
    }
    /*
     * Resolve relational data from external methods
     *
     * example usage:
     *
     * in Board::__construct()
     * 		$this->addResolvable('owner')
     *
     * in BoardMapper
     * 		$board->resolveRelation('owner', function($owner) use (&$userManager) {
     * 			return new \OCA\Deck\Db\User($userManager->get($owner));
     * 		});
     *
     * resolved values can be obtained by calling resolveProperty
     * e.g. $board->resolveOwner()
     *
     * @param string $property name of the property
     * @param callable $resolver anonymous function to resolve relational
     * data defined by $property as unique identifier
     * @throws \Exception
     */
    public function resolveRelation($property, $resolver)
    {
    }
    public function __call(string $methodName, array $args)
    {
    }
}
