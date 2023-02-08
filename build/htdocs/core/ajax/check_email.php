<?php
/*
 * Copyright © 2019-2021  Frédéric France     <frederic.france@netlogic.fr>
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

header('Content-type: application/json');
$langs->load('googleapi@googleapi');
$info = $langs->trans("GoogleApiUnreadEmailNone");
$response = [
	'unread' => 0,
	'info' => $info,
];
if (is_object($user)) {
	$sql = 'SELECT unread FROM ' . MAIN_DB_PREFIX . 'googleapi_mailboxes WHERE userid=' . (int) $user->id;
	$resql = $db->query($sql);
	if ($resql && $obj = $db->fetch_object($resql)) {
		if ($obj->unread == 1) {
			$info = $langs->trans("GoogleApiUnreadEmailOne");
		} elseif ($obj->unread > 1) {
			$info = $langs->trans("GoogleApiUnreadEmailMany", $obj->unread);
		}
		$response = [
			'unread' => $obj->unread,
			'info' => $info,
		];
	}
}
print json_encode($response);
$db->close();
