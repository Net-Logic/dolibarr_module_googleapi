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
 * \file    googleapi/core/ajax/ecmgoogledriverename.php
 * \ingroup googleapi
 * \brief   Renames a Google Drive file or folder, returns a JSON status so the caller
 *          can show the user a real success/error message instead of discarding it.
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

top_httphead('application/json');

$langs->loadLangs(['googleapi@googleapi']);

$response = ['success' => false, 'message' => ''];

if (!$user->hasRight('googleapi', 'write')) {
	$response['message'] = $langs->trans("NotEnoughPermissions");
	print json_encode($response);
	exit;
}

$fileid = GETPOST('fileid', 'alpha');
$newname = GETPOST('newname', 'alphanohtml');

if ($fileid && $newname) {
	$driveservice = getGoogleDriveService($user);
	if (is_object($driveservice)) {
		try {
			$drivefile = new \Google\Service\Drive\DriveFile();
			$drivefile->setName($newname);
			$driveservice->files->update($fileid, $drivefile);
			$response['success'] = true;
			$response['message'] = $langs->trans("GoogleApiDriveFileRenamed");
		} catch (Exception $e) {
			$response['message'] = $langs->trans("GoogleApiErrorDriveApi", $e->getMessage());
		}
	} else {
		$response['message'] = $langs->trans("GoogleApiDriveNotConnected");
	}
} else {
	$response['message'] = $langs->trans("ErrorFieldRequired", $langs->trans("Name"));
}

print json_encode($response);
