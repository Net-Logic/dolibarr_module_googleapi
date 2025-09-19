<?php
/* Copyright (C) 2019-2025  Frédéric France      <frederic.france@netlogic.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file    googleapi/notifications.php
 * \ingroup googleapi
 * \brief   notifications file for module Google.
 */

$defines = [
	'NOLOGIN',
	'NOREQUIREMENU',
	'NOREQUIREHTML',
	'NOREQUIREAJAX',
	// 'NOREQUIREUSER',
	// 'NOREQUIREDB',
	'NOREQUIRESOC',
	// 'NOREQUIRETRAN',
	'NOCSRFCHECK',
	'NOTOKENRENEWAL',
];

// Load Dolibarr environment
include 'config.php';

dol_include_once('/prune/vendor/autoload.php');
dol_include_once('/googleapi/lib/googleapi.lib.php');


// googleapi envoie une validation de l'url à la demande de création d'une 'subscription' ? A vérifier et à supprimer
// $validationToken = GETPOST('validationToken', 'alpha');
// if (!empty($validationToken)) {
// 	header("Content-Type: text/plain");
// 	echo $validationToken;
// 	dol_syslog("googleapi_logs validation token" . $validationToken, LOG_NOTICE);

// 	$db->close();
// 	exit;
// }

//$input = file_get_contents('php://input');

$now = dol_now();
$error = 0;
//$notifications = json_decode($input);
//dol_syslog(' '.print_r($notifications, true), LOG_NOTICE);
dol_syslog('SERVER ' . print_r($_SERVER, true), LOG_DEBUG);
// dol_syslog('POST '.print_r($_POST, true), LOG_NOTICE);
// dol_syslog('GET '.print_r($_GET, true), LOG_NOTICE);
// $input = file_get_contents('php://input');
// dol_syslog('INPUT '.print_r($input, true), LOG_NOTICE);

// Content-Type: application/json; utf-8
// Content-Length: 0
// X-Goog-Channel-ID: 4ba78bf0-6a47-11e2-bcfd-0800200c9a66
// X-Goog-Channel-Token: 398348u3tu83ut8uu38
// X-Goog-Channel-Expiration: Tue, 19 Nov 2013 01:13:52 GMT
// X-Goog-Resource-ID:  ret08u3rv24htgh289g
// X-Goog-Resource-URI: https://www.googleapis.com/calendar/v3/calendars/my_calendar@gmail.com/events
// X-Goog-Resource-State:  exists
// X-Goog-Message-Number: 10

// X-Goog-Channel-ID            UUID or other unique string you provided to identify this notification channel.
// X-Goog-Message-Number        Integer that identifies this message for this notification channel. Value is always 1 for sync messages. Message numbers increase for each subsequent message on the channel, but they are not sequential.
// X-Goog-Resource-ID           An opaque value that identifies the watched resource. This ID is stable across API versions.
// X-Goog-Resource-State        The new resource state, which triggered the notification. Possible values: sync, exists, or not_exists.
// X-Goog-Resource-URI          An API-version-specific identifier for the watched resource.
// Sometimes present
// X-Goog-Channel-Expiration    Date and time of notification channel expiration, expressed in human-readable format. Only present if defined.
// X-Goog-Channel-Token         Notification channel token that was set by your application, and that you can use to verify the source of notification. Only present if defined.

// Notification messages posted by the Google Calendar API to your receiving URL do not include a message body.

$sql = "SELECT rowid, userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber FROM " . MAIN_DB_PREFIX . "googleapi_watchs";
$sql .= ' WHERE uuid="' . $db->escape($_SERVER['HTTP_X_GOOG_CHANNEL_ID']) . '"';
dol_syslog($sql, LOG_NOTICE);
$resql = $db->query($sql);
$row = $db->fetch_object($resql);
dol_syslog(print_r($row, true), LOG_NOTICE);
if ($row) {
	$now = dol_now();
	$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'googleapi_watchs SET lastmessagenumber=' . ((int) $_SERVER['HTTP_X_GOOG_MESSAGE_NUMBER']) . ' WHERE rowid=' . (int) $row->rowid;
	//dol_syslog($sql, LOG_NOTICE);
	$resql = $db->query($sql);
	// fetch updates for user since last sync
	$fuser = new User($db);
	$fuser->fetch($row->userid);
	if (empty($fuser->array_options['options_googleapi_calendarId'])) {
		$calendarId = 'primary';
	} else {
		$calendarId = $fuser->array_options['options_googleapi_calendarId'];
	}
	//dol_syslog(print_r($fuser->array_options, true), LOG_NOTICE);
	$client = getGoogleApiClient($fuser);
	$service = new Google\Service\Calendar($client);
	$opts = [];
	if (!empty($fuser->array_options['options_googleapi_lastevent_sync'])) {
		$opts = [
			'updatedMin' => date(DATE_ATOM, (int) $fuser->array_options['options_googleapi_lastevent_sync']),
			'singleEvents' => true,
		];
	}
	// $optParams = array(
	// 	'maxResults' => 2500,
	// 	'singleEvents' => true,
	// 	'pageToken' => $pageToken,
	// 	'timeMin' => $minCheck,
	// 	'timeMax' => $maxCheck,
	// );
	//dol_syslog(print_r($opts, true), LOG_NOTICE);
	try {
		$events = $service->events->listEvents($calendarId, $opts);
		dol_syslog("Events : " . print_r($events, true), LOG_NOTICE);
		$main_tz = $events->getTimeZone();
		dol_syslog("Main Timezone : " . print_r($main_tz, true), LOG_NOTICE);
		//dol_syslog(print_r($events, true), LOG_NOTICE);
		$items = $events->getItems();
		foreach ($items as $item) {
			//if ($item->getEventType() != 'default') continue;
			dol_syslog(print_r($item, true), LOG_NOTICE);
			// id dans dolibarr
			$sql = 'SELECT fk_object FROM ' . MAIN_DB_PREFIX . 'actioncomm_extrafields WHERE googleapi_EventId="' . $db->escape($item->getId()) . '"';
			$resql = $db->query($sql);
			if ($resql && $obj = $db->fetch_object($resql)) {
				if ($item->getStatus() == 'cancelled') {
					// delete
					require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
					$evt = new ActionComm($db);
					$evt->fetch($obj->fk_object);
					$evt->fetch_optionals();
					$evt->fetch_userassigned();
					$evt->oldcopy = clone $evt;
					$evt->context['googleapi'] = $db->escape($item->getId());
					$res = $evt->delete($fuser, 0);
					if ($res < 0) {
						dol_syslog("googleapi notifications DELETE actioncomm " . $evt->error, LOG_ERR);
					} else {
						dol_syslog("googleapi notifications DELETE actioncomm OK", LOG_NOTICE);
					}
				} else {
					$start = $item->getStart();
					// dol_syslog("Start : ".print_r($start, true), LOG_NOTICE);
					$tz = new \DateTimeZone($main_tz);
					$starttz = new \DateTimeZone($start->getTimeZone());
					$offset_start = 0;
					$offset_end = 0;
					if ($start->getDate()) {
						// getDolGlobalString('MAIN_STORE_FULL_EVENT_IN_GMT')
						$tz = new \DateTimeZone('UTC');
						$date_start = \DateTime::createFromFormat("Y-m-d H:i:s", $start->getDate().' 00:00:00', $tz);
					} else {
						$date_start = \DateTime::createFromFormat(DATE_ATOM, $start->getDateTime(), $starttz);
						// $offset_start = $tz->getOffset($date_start);
					}
					$end = $item->getEnd();
					$endtz = new \DateTimeZone($end->getTimeZone());
					// dol_syslog("End : ".print_r($end, true), LOG_DEBUG);
					if ($start->getDate()) {
						$date_end = \DateTime::createFromFormat("Y-m-d H:i:s", $end->getDate().' 00:00:00', $tz);
						$offset_end = -1; // dolibarr world
					} else {
						$date_end = \DateTime::createFromFormat(DATE_ATOM, $end->getDateTime(), $endtz);
						// $offset_end = $tz->getOffset($date_end);
					}
					// dol_syslog("googleapi notifications entering update actioncomm id " . $obj->fk_object, LOG_NOTICE);
					// mise à jour
					require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
					$evt = new ActionComm($db);
					$evt->fetch($obj->fk_object);
					$evt->fetch_optionals();
					$evt->fetch_userassigned();
					$evt->oldcopy = clone $evt;
					$evt->datep = $date_start->getTimeStamp() + $offset_start;
					$evt->datef = $date_end->getTimeStamp() + $offset_end;
					// $evt->fulldayevent = $item->getIsAllDay() ? 1 : 0;
					$label = $item->getSummary();
					$evt->label = empty($label) ? 'NoSubject' : $label;
					// note_private est prioritaire mais pour combien de temps
					$description = $item->getDescription();
					// $description = print_r($response, true);
					// Replace bad character by '?' including utf8 four bytes
					// $description = preg_replace('/[x00-x08x10x0Bx0Cx0E-x19x7F]|[x00-x7F][x80-xBF]+|([xC0xC1]|[xF0-xFF])[x80-xBF]*'.
					// '|[xC2-xDF]((?![x80-xBF])|[x80-xBF]{2,})|[xE0-xEF](([x80-xBF](?![x80-xBF]))|(?![x80-xBF]{2})|[x80-xBF]{3,})/S', '?', $description);
					// $description = preg_replace('/xE0[x80-x9F][x80-xBF]|xED[xA0-xBF][x80-xBF]/S', '?', $description);

					$evt->location = $item->getLocation();
					$evt->note_private = $description;
					$evt->note = $description;
					$evt->context['googleapi'] = $db->escape($item->getId());
					$res = $evt->update($fuser, 0);
					if ($res < 0) {
						dol_syslog("googleapi notifications update actioncomm " . $evt->error, LOG_ERR);
					} else {
						dol_syslog("googleapi notifications update actioncomm OK", LOG_NOTICE);
					}
				}
			} else {
				//dol_syslog("googleapi notifications entering CREATE actioncomm googleId " . $item->getId(), LOG_NOTICE);
				if ($item->getStatus() == 'cancelled') {
					// delete is not possible, he doesn't exist in dolibarr
				} else {
					$start = $item->getStart();
					//dol_syslog("Start : ".print_r($start, true), LOG_NOTICE);
					$tz = new \DateTimeZone($main_tz);
					$offset_start = 0;
					$offset_end = 0;
					$fullday = 0;
					//dol_syslog("Timezone : ".print_r($tz, true), LOG_NOTICE);
					if ($start->getDate()) {
						$tz = new \DateTimeZone('UTC');
						$date_start = \DateTime::createFromFormat("Y-m-d H:i:s", $start->getDate().' 00:00:00', $tz);
						$fullday = 1;
					} else {
						$date_start = \DateTime::createFromFormat("Y-m-d\TH:i:sP", $start->getDateTime(), $tz);
						$offset_start = $tz->getOffset($date_start);
					}
					//dol_syslog("Date Start : ".print_r($date_start, true), LOG_NOTICE);
					$end = $item->getEnd();
					//dol_syslog("End : ".print_r($end, true), LOG_NOTICE);
					if ($end->getDate()) {
						$date_end = \DateTime::createFromFormat("Y-m-d H:i:s", $end->getDate().' 00:00:00', $tz);
						$offset_end = -1; // dolibarr world
					} else {
						$date_end = \DateTime::createFromFormat("Y-m-d\TH:i:sP", $end->getDateTime(), $tz);
						$offset_end = $tz->getOffset($date_end);
					}
					googleapiCreateActioncomm(
						// propriétaire
						$fuser,
						'AC_GAPI_CAL',
						$date_start->getTimestamp() + $offset_start,
						$date_end->getTimestamp() + $offset_end,
						$item->getSummary(),
						$item->getDescription(),
						// location
						$item->getLocation(),
						// event id
						$item->getId(),
						// notrigger
						$fullday,
						0
					);
				}
			}
		}
	} catch (Exception $e) {
		dol_syslog($e->getMessage(), LOG_NOTICE);
	}
	// $pageToken = $events->getNextPageToken();
	// dol_syslog(print_r($pageToken, true), LOG_NOTICE);
	$fuser->array_options['options_googleapi_lastevent_sync'] = $now;
	$fuser->update($fuser, 1);
	//dol_syslog(print_r($fuser->error, true), LOG_NOTICE);
	//dol_syslog(print_r($events, true), LOG_NOTICE);
}
$db->close();
