<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/** Lazy inspection of the status-menu write. Nothing native is constructed. */
final class StatusWriteSurface {
	/** Native service the controller uses for a manual type change. */
	public const SERVICE = 'OCA\\UserStatus\\Service\\StatusService';

	/** @param string $service service class; a test may pass a stand-in */
	public function __construct(private string $service = self::SERVICE) {
	}

	/** @return bool setStatus and removeBackupUserStatus have the controller's signatures */
	public function supported(): bool {
		try {
			return class_exists($this->service)
				&& $this->acceptsSetStatus()
				&& $this->backupRemoval();
		} catch (Throwable) {
			return false;
		}
	}

	/** @return bool the message methods and removeBackupUserStatus have the controller's signatures */
	public function messageSupported(): bool {
		try {
			return class_exists($this->service)
				&& $this->acceptsPredefined()
				&& $this->acceptsCustom()
				&& $this->acceptsClear()
				&& $this->backupRemoval();
		} catch (Throwable) {
			return false;
		}
	}

	/** @return bool setStatus(string, string, ?int, bool): UserStatus, timestamp nullable and still required */
	private function acceptsSetStatus(): bool {
		$method = new ReflectionMethod($this->service, 'setStatus');
		$parameters = $method->getParameters();
		return $method->isPublic() && !$method->isStatic() && !$method->returnsReference()
			&& count($parameters) === 4 && $method->getNumberOfRequiredParameters() === 4
			&& $this->named($parameters[0], 'string', false, false)
			&& $this->named($parameters[1], 'string', false, false)
			&& $this->named($parameters[2], 'int', true, false)
			&& $this->named($parameters[3], 'bool', false, false)
			&& $this->returnsUserStatus($method);
	}

	/** @return bool the method returns the native user-status entity */
	private function returnsUserStatus(ReflectionMethod $method): bool {
		return $method->getReturnType() instanceof ReflectionNamedType
			&& $method->getReturnType()->getName() === 'OCA\\UserStatus\\Db\\UserStatus';
	}

	/** @return bool setPredefinedMessage(string, string, ?int): UserStatus, clearAt nullable and still required */
	private function acceptsPredefined(): bool {
		$method = new ReflectionMethod($this->service, 'setPredefinedMessage');
		$parameters = $method->getParameters();
		return $method->isPublic() && !$method->isStatic() && !$method->returnsReference()
			&& count($parameters) === 3 && $method->getNumberOfRequiredParameters() === 3
			&& $this->named($parameters[0], 'string', false, false)
			&& $this->named($parameters[1], 'string', false, false)
			&& $this->named($parameters[2], 'int', true, false)
			&& $this->returnsUserStatus($method);
	}

	/** @return bool setCustomMessage(string, ?string, ?string, ?int): UserStatus, every argument required */
	private function acceptsCustom(): bool {
		$method = new ReflectionMethod($this->service, 'setCustomMessage');
		$parameters = $method->getParameters();
		return $method->isPublic() && !$method->isStatic() && !$method->returnsReference()
			&& count($parameters) === 4 && $method->getNumberOfRequiredParameters() === 4
			&& $this->named($parameters[0], 'string', false, false)
			&& $this->named($parameters[1], 'string', true, false)
			&& $this->named($parameters[2], 'string', true, false)
			&& $this->named($parameters[3], 'int', true, false)
			&& $this->returnsUserStatus($method);
	}

	/** @return bool clearMessage(string): bool */
	private function acceptsClear(): bool {
		$method = new ReflectionMethod($this->service, 'clearMessage');
		$parameters = $method->getParameters();
		return $method->isPublic() && !$method->isStatic() && !$method->returnsReference()
			&& count($parameters) === 1 && $this->named($parameters[0], 'string', false, false)
			&& $method->getReturnType() instanceof ReflectionNamedType
			&& $method->getReturnType()->getName() === 'bool';
	}

	/** @return bool removeBackupUserStatus(string): bool */
	private function backupRemoval(): bool {
		$method = new ReflectionMethod($this->service, 'removeBackupUserStatus');
		$parameters = $method->getParameters();
		return $method->isPublic() && !$method->isStatic() && !$method->returnsReference()
			&& count($parameters) === 1 && $this->named($parameters[0], 'string', false, false)
			&& $method->getReturnType() instanceof ReflectionNamedType
			&& $method->getReturnType()->getName() === 'bool';
	}

	/** @return bool one parameter has the expected named type */
	private function named(\ReflectionParameter $parameter, string $type, bool $nullable, bool $optional): bool {
		$declared = $parameter->getType();
		return $declared instanceof ReflectionNamedType
			&& $declared->getName() === $type
			&& $declared->allowsNull() === $nullable
			&& $parameter->isOptional() === $optional
			&& !$parameter->isPassedByReference()
			&& !$parameter->isVariadic();
	}
}
