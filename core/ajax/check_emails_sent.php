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
$data = [];
$action = GETPOST('action', 'aZ09');
$module = GETPOST('module', 'alpha');
$id = GETPOST('id', 'int');
$page = (int) GETPOST('page', 'int');
if ($page <= 0) {
	$page = 1;
}
$perPage = (int) GETPOST('perPage', 'int');
if ($perPage <= 0) {
	$perPage = 1;
}
if (is_object($user) && $action == 'getemails') {
	$sql = 'SELECT rowid, userid, fk_object, messageid FROM ' . MAIN_DB_PREFIX . 'googleapi_emails_sent';
	$sql .= ' WHERE userid=' . (int) $user->id . ' AND module="' . $db->escape($module) . '"';
	$sql .= ' AND fk_object=' . (int) $id;
	$resql = $db->query($sql);
	$totalcount = $db->num_rows($resql);
	$sql .= $db->plimit($perPage, ($page - 1) * $perPage);
	$resql = $db->query($sql);
	while ($resql && $obj = $db->fetch_object($resql)) {
		$data[] = $obj;
	}
}
$response = [
	"result" => true,
	"data" => [
		"contents" => $data,
		"pagination" => [
			"page" => $page,
			"totalCount" => $totalcount
		]
	]
];
print json_encode($response);
$db->close();
