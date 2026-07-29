<?php
/* Copyright (C) 2026  Frédéric France     <frederic.france@free.fr>
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
 * \file    googleapi/core/ajax/ecmgoogledrivetree.php
 * \ingroup googleapi
 * \brief   Returns the sub-folders of a Google Drive folder, for the jqueryFileTree plugin
 */

$defines = [
	'NOTOKENRENEWAL',
	'NOREQUIREMENU',
	'NOREQUIREHTML',
	'NOREQUIREAJAX',
];

// Load Dolibarr environment
include '../../config.php';
require_once '../../lib/googleapi.lib.php';

top_httphead();

if (!$user->hasRight('googleapi', 'read')) {
	accessforbidden();
}

$dir = GETPOST('dir', 'alpha');
$parentid = trim((string) $dir, '/');
if ($parentid == '') {
	$parentid = 'root';
}

$driveservice = getGoogleDriveService($user);

print '<ul class="ecmjqft" style="display: none;">'."\n";

if (is_object($driveservice)) {
	try {
		$query = "'".googleapiDriveEscapeId($parentid)."' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false";
		$result = $driveservice->files->listFiles(array(
			'q' => $query,
			'fields' => 'files(id,name)',
			'orderBy' => 'name',
			'pageSize' => 1000,
		));
		foreach ($result->getFiles() as $folder) {
			print '<li class="directory collapsed">';
			print '<a class="jqft ecmjqft" href="#" rel="'.dol_escape_htmltag($folder->getId()).'/" onclick="ecmGoogleDriveNavigate(\''.dol_escape_js($folder->getId()).'\', \''.dol_escape_js($folder->getName()).'\');">';
			print dol_escape_htmltag($folder->getName());
			print '</a>';
			print '</li>'."\n";
		}
	} catch (Exception $e) {
		dol_syslog('ecmgoogledrivetree: '.$e->getMessage(), LOG_ERR);
	}
}

print '</ul>'."\n";
