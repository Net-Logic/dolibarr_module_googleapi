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
 * \file    googleapi/ecmgoogledrive.php
 * \ingroup googleapi
 * \brief   ECM tab: browse and manage the connected user's Google Drive
 */

// Load Dolibarr environment
include 'config.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ecm.lib.php';
require_once 'lib/googleapi.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('ecm', 'googleapi@googleapi'));

if (!$user->hasRight('googleapi', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$permissiontowrite = $user->hasRight('googleapi', 'write');
$permissiontodelete = $user->hasRight('googleapi', 'delete');

$driveservice = getGoogleDriveService($user);

/*
 * Actions
 */

if ($action == 'upload' && $permissiontowrite) {
	if (!empty($_FILES['userfile']['tmp_name']) && is_uploaded_file($_FILES['userfile']['tmp_name'])) {
		$parentid = GETPOST('folderid', 'alpha') ? GETPOST('folderid', 'alpha') : 'root';
		if (is_object($driveservice)) {
			try {
				$drivefile = new \Google\Service\Drive\DriveFile();
				$drivefile->setName($_FILES['userfile']['name']);
				$drivefile->setParents(array($parentid));
				$driveservice->files->create($drivefile, array(
					'data' => file_get_contents($_FILES['userfile']['tmp_name']),
					'mimeType' => $_FILES['userfile']['type'] ? $_FILES['userfile']['type'] : 'application/octet-stream',
					'uploadType' => 'multipart',
				));
				setEventMessages($langs->trans("GoogleApiDriveFileUploaded"), null, 'mesgs');
			} catch (Exception $e) {
				setEventMessages($langs->trans("GoogleApiErrorDriveApi", $e->getMessage()), null, 'errors');
			}
		}
	} else {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("File")), null, 'errors');
	}
}

if ($action == 'renamedrivefile' && $permissiontowrite) {
	$fileid = GETPOST('fileid', 'alpha');
	$newname = GETPOST('newname', 'alphanohtml');
	if ($fileid && $newname && is_object($driveservice)) {
		try {
			$drivefile = new \Google\Service\Drive\DriveFile();
			$drivefile->setName($newname);
			$driveservice->files->update($fileid, $drivefile);
			setEventMessages($langs->trans("GoogleApiDriveFileRenamed"), null, 'mesgs');
		} catch (Exception $e) {
			setEventMessages($langs->trans("GoogleApiErrorDriveApi", $e->getMessage()), null, 'errors');
		}
	}
}

if ($action == 'deletedrivefile' && $permissiontodelete) {
	$fileid = GETPOST('fileid', 'alpha');
	if ($fileid && is_object($driveservice)) {
		try {
			$drivefile = new \Google\Service\Drive\DriveFile();
			$drivefile->setTrashed(true);
			$driveservice->files->update($fileid, $drivefile);
			setEventMessages($langs->trans("GoogleApiDriveFileDeleted"), null, 'mesgs');
		} catch (Exception $e) {
			setEventMessages($langs->trans("GoogleApiErrorDriveApi", $e->getMessage()), null, 'errors');
		}
	}
}

/*
 * View
 */

// The jqueryFileTree plugin is not loaded globally by Dolibarr core: each page using it must load it
// itself (same as htdocs/ecm/index.php does). It must come before our own JS, which calls .fileTree().
$morejs = array(
	'public/includes/jquery/plugins/jqueryFileTree/jqueryFileTree.js',
	'/googleapi/js/ecmgoogledrive.js.php',
);

llxHeader('', $langs->trans("ECMArea"), '', '', 0, 0, $morejs, '', '', 'mod-googleapi page-ecmgoogledrive');

$head = ecm_prepare_dasboard_head();

print dol_get_fiche_head($head, 'googledrive', '', -1, '');

if (!is_object($driveservice)) {
	print '<div class="opacitymedium">'.$langs->trans("GoogleApiDriveNotConnected").'</div><br>'."\n";
	$urltoconnect = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1).'?backtourl='.urlencode(dol_buildpath('/googleapi/ecmgoogledrive.php', 1));
	print '<a class="butAction" href="'.$urltoconnect.'">'.$langs->trans("GoogleApiConnectDrive").'</a>'."\n";
} else {
	// Reuse the same #ecm-layout-west/#ecm-layout-center classes as native ECM (core/tpl/filemanager.tpl.php)
	// for width/positioning. The panel box itself uses plain divs (not the ".liste" table used natively)
	// because that table relies on a percentage height that only resolves correctly in some browsers once
	// content is injected dynamically by jqueryFileTree (confirmed broken in Firefox: the box collapsed to
	// a single row while the rest of the tree rendered outside it). Plain divs have no such height dependency.
	print '<style>
.ecmgdrivetreepanel { background: #FFF; border: 1px solid #e5e5e5; }
.ecmgdrivetreepanel-title { padding: 5px 8px; font-weight: bold; background: #f5f5f5; border-bottom: 1px solid #e5e5e5; }
</style>'."\n";
	print '<div id="containerlayout">'."\n";
	print '<div id="ecm-layout-west" class="inline-block">'."\n";
	print '<div class="ecmgdrivetreepanel">'."\n";
	print '<div class="ecmgdrivetreepanel-title">'.$langs->trans("ECMSections").'</div>'."\n";
	print '<div id="filetree" class="ecmfiletree"></div>'."\n";
	print '</div>'."\n";
	print '</div>'."\n";
	print '<div id="ecm-layout-center" class="inline-block">'."\n";
	print '<div id="ecmgdrive-breadcrumb"></div>'."\n";
	if ($permissiontowrite) {
		print '<form name="formulaireecmgdriveupload" action="'.$_SERVER['PHP_SELF'].'" method="POST" enctype="multipart/form-data">'."\n";
		print '<input type="hidden" name="token" value="'.newToken().'">'."\n";
		print '<input type="hidden" name="action" value="upload">'."\n";
		print '<input type="hidden" name="folderid" id="ecmgdrive_folderid" value="root">'."\n";
		print '<input type="file" name="userfile">'."\n";
		print '<input type="submit" class="button" value="'.$langs->trans("GoogleApiUploadToThisFolder").'">'."\n";
		print '</form>'."\n";
	} else {
		print '<input type="hidden" id="ecmgdrive_folderid" value="root">'."\n";
	}
	print '<div id="ecmgdrive-filelist"></div>'."\n";
	print '</div>'."\n";
	print '</div>'."\n";
}

print dol_get_fiche_end();

llxFooter();

$db->close();
