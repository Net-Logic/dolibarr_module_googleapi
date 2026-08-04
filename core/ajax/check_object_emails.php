<?php
/*
 * Copyright © 2025  Jean-Rémi Taponier     <jean-remi@netlogic.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  return json mail check
 *
 */

$defines = [
	'NOCSRFCHECK',
	'NOTOKENRENEWAL',
	'NOREQUIREMENU',
	'NOREQUIREHTML',
	'NOREQUIREAJAX',
	'NOREQUIRESOC',
];

// Load Dolibarr environment
include '../../config.php';
require_once __DIR__ . '/../../lib/googleapi.lib.php';

/**
 * @global $db 			DoliDB
 * @global $user 		User
 * @global $mysoc 		Societe
 * @global $langs 		Translate
 * @global $conf 		Conf
 * @global $hookmanager HookManager
 */
global $db, $user, $mysoc, $langs, $conf, $hookmanager;

if ($user->socid > 0) {
	print json_encode(['message' => 'Unauthorized']);
	exit();
}

$objectType = GETPOST('object_type');
$objectId = GETPOSTINT('object_id');
$page= GETPOSTINT('page') ?: 1;
$perPage = GETPOSTINT('perPage') ?: 25;

//TODO Add in configuration
$showContactsMessages = true;

$response = ['result' => false, 'data' => ['contents' => []]];
$data = [];
if (!empty($objectId)) {
	switch ($objectType) {
		case 'societe':
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$object = new Societe($db);
			$object->fetch($objectId);
			if (!$object->id || (!$showContactsMessages && !$object->email)) {
				print json_encode($response);
				exit();
			}
			$gMailFilterParts = [];
			if ($object->email) {
				$gMailFilterParts = ["(to:{$object->email} OR from:{$object->email})"];
			}

			//TODO Remove when API data will be stored in db
			if ($showContactsMessages) {
				$contactsEmails = array_column($object->contact_array_objects(), 'email');
				foreach ($contactsEmails as $contactEmail) {
					$gMailFilterParts[] = "(to:{$contactEmail} OR from:{$contactEmail})";
				}
			}

			if (!$gMailFilterParts) {
				print json_encode($response);
				exit();
			}

			$client = getGoogleApiClient($user);
			$gMailService = new Google_Service_Gmail($client);
			$gUser = 'me';


			$gMailFilter = implode(' OR ', $gMailFilterParts);
			$gParams = ['q' => $gMailFilter, 'maxResults' => $perPage];

			//TODO Remove when API data will be stored in db
			if ($_SESSION["googleapi_page_token_{$object->element}_{$object->id}"]) {
				$gParams['pageToken'] = $_SESSION["googleapi_page_token_{$object->element}_{$object->id}"];
			}
			$messagesResponse = $gMailService->users_messages->listUsersMessages($gUser, $gParams);

			if ($messagesResponse->getMessages()) {
				foreach ($messagesResponse->getMessages() as $message) {
					//TODO store fullMessage to avoid API requests
					$fullMessage = $gMailService->users_messages->get($gUser, $message->getId(), ['format' => 'full']);

					$payload = $fullMessage->getPayload();

					$body = '';
					if ($payload->getBody() && $payload->getBody()->getData()) {
						$body = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload->getBody()->getData()));
					} else {
						if ($payload->getParts()) {
							foreach ($payload->getParts() as $part) {
								$mimeType = $part->getMimeType();

								if ($mimeType === 'text/html' || $mimeType === 'text/plain') {
									$mailData = $part->getBody()->getData();
									if ($mailData) {
										$body = base64_decode(str_replace(['-', '_'], ['+', '/'], $mailData));
										break; // Keep only the first type
									}
								}
							}
						}
					}

					$headers = $fullMessage->getPayload()->getHeaders();

					$subject = $from = $to = $date = '';
					foreach ($headers as $header) {
						if (in_array($header->getName(), ['Subject', 'From', 'To', 'Date'])) {
							$headerName = mb_strtolower($header->getName());
							$$headerName = $header->getValue();
						}
					}

					if ($date) {
						$date = dol_print_date(strtotime($date), 'dayhour');
					}
					$data[] = [
						'from' => $from,
						'to' => $to,
						'date' => $date,
						'subject' => $subject,
						'body_snippet' => $fullMessage->getSnippet(),
						'body' => $body,
					];
				}
			}
			break;
		case 'contact':
			//TODO
	}
}

if ($data) {
	$response['result'] = true;
	$response['data']['pagination'] = [
		'page' => $page,
		'count' => count($data),
	];
	$response['data']['contents'] = $data;
}
header('Content-type: application/json');
print json_encode($response);
$db->close();
