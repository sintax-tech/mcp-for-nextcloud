<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\MailUnavailableException;

/** Constructs native draft and Outbox values after the actor enablement check. */
final class MailNativeMessages {
	/** OCA\Mail\Model\NewMessageData: the message a draft save takes. */
	private const NEW_MESSAGE_DATA_CLASS = 'OCA\\Mail\\Model\\NewMessageData';

	/** OCA\Mail\AddressList: recipients parsed as the Mail app parses them. */
	private const ADDRESS_LIST_CLASS = 'OCA\\Mail\\AddressList';

	/** OCA\Mail\Db\LocalMessage: a message of the Outbox, and its status values. */
	private const LOCAL_MESSAGE_CLASS = 'OCA\\Mail\\Db\\LocalMessage';

	/** Static native value construction only. */
	private function __construct() {}

	/**
	 * A new OCA\Mail\Model\NewMessageData for a draft: plain text, no attachment, recipients parsed by
	 * OCA\Mail\AddressList::parse() as the Mail app parses a request.
	 *
	 * @param bool $enabled whether the Mail app is enabled for the actor on this call
	 * @param object $account OCA\Mail\Account the user owns
	 * @param string $to recipients, comma separated
	 * @param string $cc copy recipients, comma separated
	 * @param string $bcc blind copy recipients, comma separated
	 * @param string $subject subject
	 * @param string $body plain text or sanitized HTML
	 * @param bool $html native isHtml value, plain text by default
	 * @return object OCA\Mail\Model\NewMessageData
	 * @throws MailUnavailableException when Mail is disabled for the user or a class is not loaded
	 */
	public static function newMessageData(bool $enabled, object $account, string $to, string $cc, string $bcc, string $subject, string $body, bool $html = false): object {
		$data = self::NEW_MESSAGE_DATA_CLASS;
		$list = self::ADDRESS_LIST_CLASS;
		if (!$enabled || !class_exists($data) || !class_exists($list)) {
			throw new MailUnavailableException();
		}
		return new $data($account, $list::parse($to), $list::parse($cc), $list::parse($bcc), $subject, $body, [], $html);
	}

	/**
	 * A new outgoing OCA\Mail\Db\LocalMessage, as OutboxController::create() fills it for a plain text message: no send
	 * time, no alias, no reply, no signature or encryption.
	 *
	 * @param bool $enabled whether the Mail app is enabled for the actor on this call
	 * @param int $accountId the account it is sent from
	 * @param string $subject subject
	 * @param string $body plain text
	 * @return object OCA\Mail\Db\LocalMessage
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public static function newOutgoingMessage(bool $enabled, int $accountId, string $subject, string $body): object {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$enabled || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$message = new $class();
		$message->setType(constant($class . '::TYPE_OUTGOING'));
		$message->setAccountId($accountId);
		$message->setSubject($subject);
		$message->setBodyPlain($body);
		$message->setBodyHtml(null);
		$message->setHtml(false);
		$message->setSendAt(null);
		return $message;
	}

	/**
	 * The values of OCA\Mail\Db\LocalMessage status constants in the installed Mail; a name it lacks is left out.
	 *
	 * @param bool $enabled whether the Mail app is enabled for the actor on this call
	 * @param list<string> $names constant names, such as STATUS_PROCESSED
	 * @return array<string, int> value by name
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public static function localMessageStatuses(bool $enabled, array $names): array {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$enabled || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$values = [];
		foreach ($names as $name) {
			if (defined($class . '::' . $name)) {
				$values[$name] = (int)constant($class . '::' . $name);
			}
		}
		return $values;
	}

}
