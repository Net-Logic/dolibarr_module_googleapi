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

// Google Drive mutation actions (upload, rename, delete) are added here by later tasks.

/*
 * View
 */

$morejs = array('/googleapi/js/ecmgoogledrive.js.php');

llxHeader('', $langs->trans("ECMArea"), '', '', 0, 0, $morejs, '', '', 'mod-googleapi page-ecmgoogledrive');

$head = ecm_prepare_dasboard_head();

print dol_get_fiche_head($head, 'googledrive', '', -1, '');

if (!is_object($driveservice)) {
	print '<div class="opacitymedium">'.$langs->trans("GoogleApiDriveNotConnected").'</div><br>'."\n";
	$urltoconnect = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1).'?backtourl='.urlencode(dol_buildpath('/googleapi/ecmgoogledrive.php', 1));
	print '<a class="butAction" href="'.$urltoconnect.'">'.$langs->trans("GoogleApiConnectDrive").'</a>'."\n";
} else {
	print '<div class="fichecenter">'."\n";
	print '<div class="ecmgdrive-left" style="float:left; width: 30%;">'."\n";
	print '<div id="filetree"></div>'."\n";
	print '</div>'."\n";
	print '<div class="ecmgdrive-right" style="float:left; width: 68%; margin-left: 2%;">'."\n";
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
	print '<div style="clear:both;"></div>'."\n";
	print '</div>'."\n";
}

print dol_get_fiche_end();

llxFooter();

$db->close();
