<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Throwaway SMTP sink of the Mail cells, for mail_send_message: it listens on the loopback of the Nextcloud container
 * only, relays nothing and opens no connection of its own, and writes every session it receives (EHLO, MAIL FROM, each
 * RCPT TO with the answer it gave, whether DATA came and the message itself) as one JSON line to its log. What it
 * answers depends on the local part of the recipients, so a test chooses the outcome by the addresses it sends to:
 * - `quota@…`: 452 4.2.2 to that RCPT (insufficient storage);
 * - `auth@…`: 530 5.7.0 to that RCPT (authentication required);
 * - `reject@…`: 550 5.1.1 to that RCPT (no such user);
 * - `drop@…`: accepted, then the connection is closed after the message, with no answer to DATA;
 * - anything else: accepted, 250 to the message.
 * It announces no AUTH and no STARTTLS, so the Mail app's Horde client sends without either.
 *
 * Usage: php smtp-sink.php <port> <log file>
 */

[, $port, $log] = $argv + [null, null, null];
if (!ctype_digit((string)$port) || (string)$log === '') {
	fwrite(STDERR, "usage: php smtp-sink.php <port> <log file>\n");
	exit(2);
}
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
	fwrite(STDERR, "cannot listen: $error\n");
	exit(1);
}

/**
 * The local part of an address in a MAIL FROM or RCPT TO line, lower case.
 *
 * @param string $line the command line
 * @return string the address, lower case
 */
$address = static fn (string $line): string => strtolower(preg_match('/<([^>]*)>/', $line, $match) === 1 ? $match[1] : '');

while (($client = @stream_socket_accept($server, -1)) !== false) {
	$session = ['at' => microtime(true), 'ehlo' => null, 'from' => null, 'rcpt' => [], 'data' => false, 'message' => null, 'answered' => null, 'commands' => []];
	$write = static function (string $reply) use ($client, &$session): void {
		fwrite($client, $reply . "\r\n");
		$session['commands'][] = '< ' . strtok($reply, "\r\n");
	};
	$write('220 mcp-it-sink ESMTP');
	while (($line = fgets($client)) !== false) {
		$line = rtrim($line, "\r\n");
		$session['commands'][] = '> ' . $line;
		$verb = strtoupper(substr($line, 0, 4));
		if ($verb === 'EHLO' || $verb === 'HELO') {
			$session['ehlo'] = substr($line, 5);
			$write("250-mcp-it-sink\r\n250 8BITMIME");
		} elseif ($verb === 'MAIL') {
			$session['from'] = $address($line);
			$session['rcpt'] = [];
			$write('250 2.1.0 Ok');
		} elseif ($verb === 'RCPT') {
			$to = $address($line);
			$reply = match (strtok($to, '@')) {
				'quota' => '452 4.2.2 Insufficient storage',
				'auth' => '530 5.7.0 Authentication required',
				'reject' => '550 5.1.1 No such user',
				default => '250 2.1.5 Ok',
			};
			$session['rcpt'][] = ['address' => $to, 'reply' => (int)$reply];
			$write($reply);
		} elseif ($verb === 'DATA') {
			$write('354 End data with <CR><LF>.<CR><LF>');
			$message = '';
			while (($part = fgets($client)) !== false && rtrim($part, "\r\n") !== '.') {
				$message .= $part;
			}
			$session['data'] = true;
			$session['message'] = $message;
			if (in_array('drop', array_map(static fn (array $r): string => (string)strtok($r['address'], '@'), $session['rcpt']), true)) {
				$session['answered'] = false;
				break;
			}
			$session['answered'] = true;
			$write('250 2.0.0 Queued');
		} elseif ($verb === 'RSET' || $verb === 'NOOP') {
			$write('250 2.0.0 Ok');
		} elseif ($verb === 'QUIT') {
			$write('221 2.0.0 Bye');
			break;
		} else {
			$write('502 5.5.2 Not implemented');
		}
	}
	fclose($client);
	file_put_contents($log, json_encode($session, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
