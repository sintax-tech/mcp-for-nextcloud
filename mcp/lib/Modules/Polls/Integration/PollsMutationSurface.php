<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Exact public mutation signatures, checked lazily without constructing a native object. */
class PollsMutationSurface {
	/** Share service used by the native front page, including notifications. */
	public const SHARES = 'OCA\\Polls\\Service\\ShareService';
	/** Native read-only metadata mappers. */
	public const POLL_MAPPER = 'OCA\\Polls\\Db\\PollMapper';
	/** Native complete option metadata reader. */
	public const OPTION_MAPPER = 'OCA\\Polls\\Db\\OptionMapper';
	/** Native complete share metadata reader. */
	public const SHARE_MAPPER = 'OCA\\Polls\\Db\\ShareMapper';
	/** Native recipient factory. */
	public const USER_MAPPER = 'OCA\\Polls\\Db\\UserMapper';
	/** Native share entity, kept internal even when its userId is a token. */
	public const SHARE = 'OCA\\Polls\\Db\\Share';
	/** Native invitation result, nullable only in Polls 8.6. */
	public const SENT = 'OCA\\Polls\\Model\\SentResult';
	/** Exact front-page service constructor in all four pinned releases. */
	public const SHARE_DEPS = ['Psr\\Log\\LoggerInterface', 'OCP\\EventDispatcher\\IEventDispatcher', 'OCP\\Security\\ISecureRandom',
		self::SHARE_MAPPER, 'OCA\\Polls\\Service\\SystemService', self::SHARE, 'OCA\\Polls\\Service\\MailService',
		CreateSurface::SETTINGS, 'OCA\\Polls\\Service\\NotificationService', self::POLL_MAPPER, self::USER_MAPPER,
		PollsSurface::SESSION, 'OCA\\Polls\\Db\\VoteMapper', self::OPTION_MAPPER, 'OCA\\Polls\\Db\\CommentMapper', 'OCA\\Polls\\Db\\PollGroupMapper'];

	/** @param bool $nine Whether the proven 9.x signature is required. @return array<string,array<string,array{array,string|array|null}>> Required exact signatures, without invitation reference parameters. */
	public static function methods(bool $nine): array {
		$p = PollsSurface::POLL; $o = PollsSurface::OPTION; $sh = self::SHARE;
		return [
			PollsSurface::POLLS => ['__construct' => [CreateSurface::DEPS, null], 'update' => [['int', 'array'], 'array'], 'close' => [['int'], $p], 'reopen' => [['int'], $p], 'toggleArchive' => [['int'], $p]],
			PollsSurface::OPTIONS => ['__construct' => [PollsSurface::DEPENDENCIES, null], 'add' => [['int', CreateSurface::SIMPLE, 'bool'], $o],
				'update' => [$nine ? ['int', 'string', '?OCA\\Polls\\Model\\DateTimeImmutable', 'OCA\\Polls\\Model\\DateInterval'] : ['int', 'int', 'string', 'int'], $o],
				'delete' => [['int', 'bool'], $o], 'reorder' => [['int', 'array'], 'array']],
			self::SHARES => ['__construct' => [self::SHARE_DEPS, null], 'setType' => [['string', 'string'], $sh], 'setLabel' => [['string', 'string'], $sh],
				'lockByToken' => [['string', 'bool'], $sh], 'delete' => [[$sh, 'bool'], $sh]]
				+ ($nine ? ['createPublicShare' => [['int'], $sh], 'createShare' => [['int', PollsSurface::USER, 'string'], $sh]]
					: ['add' => [['int', 'string', 'string', 'string', 'string', 'string'], $sh]]),
			self::POLL_MAPPER => ['__construct' => [['OCP\\IDBConnection', PollsSurface::SESSION], null], 'get' => [['int'], $p]],
			self::OPTION_MAPPER => ['__construct' => [['OCP\\IDBConnection', PollsSurface::SESSION], null], 'findByPoll' => [['int', 'bool', 'bool'], 'array']],
			self::SHARE_MAPPER => ['__construct' => [['OCP\\IDBConnection'], null], 'findByPoll' => [['int', 'array', 'bool'], 'array']],
			self::USER_MAPPER => ['__construct' => [['OCP\\IDBConnection', 'OCP\\IUserManager'], null]],
			CreateSurface::SETTINGS => ['__construct' => [['OCP\\IAppConfig', PollsSurface::SESSION, 'OCA\\Polls\\Model\\Settings\\SystemSettings',
				'Psr\\Log\\LoggerInterface', 'OCP\\App\\IAppManager'], null], 'getPublicSharesAllowed' => [[], 'bool']],
			$p => ['getConfigurationArray' => [[], 'array']],
		];
	}
	/** @param bool $nine Selected native line. @return void @throws PollsRefusal Unsupported signature. */
	public function check(bool $nine): void {
		(new PollsSurface())->assertSupported();
		(new CreateSurface())->check();
		$s = new PollsSurface();
		foreach (self::methods($nine) as $class => $methods) {
			foreach ($methods as $method => [$params, $return]) { $s->assertMethod($class, $method, $params, $return); }
		}
		$m = new \ReflectionMethod(self::SHARES, 'sendInvitation');
		$a = $m->getParameters();
		if (!$m->isPublic() || $m->isStatic() || count($a) !== 2
			|| (string)$a[0]->getType() !== self::SHARE || $a[0]->isPassedByReference()
			|| (string)$a[1]->getType() !== ($nine ? self::SENT : '?' . self::SENT)
			|| !$a[1]->isPassedByReference() || !$a[1]->isDefaultValueAvailable()
			|| (string)$m->getReturnType() !== ($nine ? self::SENT : '?' . self::SENT)) { throw PollsRefusal::unavailable(); }
		$factory = new \ReflectionMethod(self::USER_MAPPER, $nine ? 'createUserObject' : 'getUserObject');
		if (!$factory->isPublic() || $factory->isStatic() !== $nine || $factory->getNumberOfParameters() !== 7) { throw PollsRefusal::unavailable(); }
		foreach ($factory->getParameters() as $parameter) {
			if ((string)$parameter->getType() !== 'string' || $parameter->isPassedByReference() || $parameter->isVariadic()) { throw PollsRefusal::unavailable(); }
		}
		if ($nine) {
			foreach (['OCA\\Polls\\Model\\DateTimeImmutable' => \DateTimeImmutable::class, 'OCA\\Polls\\Model\\DateInterval' => \DateInterval::class] as $class => $parent) {
				if (!class_exists($class) || !is_subclass_of($class, $parent)) { throw PollsRefusal::unavailable(); }
				$ctor = new \ReflectionMethod($class, '__construct');
				$type = $ctor->getParameters()[0]->getType();
				$types = $type instanceof \ReflectionUnionType ? array_map(static fn ($t): string => $t->getName(), $type->getTypes()) : [];
				$expected = [$class, 'int', 'string', 'null']; sort($types); sort($expected);
				if (!$ctor->isPublic() || $ctor->getNumberOfParameters() !== 1 || $types !== $expected) { throw PollsRefusal::unavailable(); }
			}
		}
		foreach ([self::POLL_MAPPER, self::OPTION_MAPPER, self::SHARE_MAPPER, self::USER_MAPPER, self::SHARES, CreateSurface::SETTINGS] as $class) {
			$constructor = new \ReflectionMethod($class, '__construct');
			if (!$constructor->isPublic()) { throw PollsRefusal::unavailable(); }
			foreach ($constructor->getParameters() as $parameter) {
				if (!$parameter->getType() instanceof \ReflectionNamedType || $parameter->getType()->isBuiltin() || $parameter->isPassedByReference()) { throw PollsRefusal::unavailable(); }
			}
		}
		foreach ([PollsSurface::POLL => ['owner', 'expire', 'deleted', 'type', 'title', 'access', 'anonymous'],
			PollsSurface::OPTION => ['pollId', 'order', 'deleted', 'owner'], self::SHARE => ['pollId', 'groupId', 'userId', 'type', 'locked', 'deleted', 'invitationSent', 'token']] as $class => $fields) {
			foreach ($fields as $field) { if (!property_exists($class, $field)) { throw PollsRefusal::unavailable(); } }
		}
	}
}
