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
 * \file    googleapi/core/ajax/ecmgoogledrivelist.php
 * \ingroup googleapi
 * \brief   Returns the HTML table listing the content (folders and files) of a Google Drive folder
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

top_httphead();

if (!$user->hasRight('googleapi', 'read')) {
	accessforbidden();
}

$permissiontowrite = $user->hasRight('googleapi', 'write');
$permissiontodelete = $user->hasRight('googleapi', 'delete');

$dir = GETPOST('dir', 'alpha');
$parentid = ($dir == '' ? 'root' : $dir);

$langs->loadLangs(array('googleapi@googleapi'));

$driveservice = getGoogleDriveService($user);

print '<table class="border centpercent">'."\n";
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Name").'</td>';
print '<td class="right">'.$langs->trans("Size").'</td>';
print '<td class="center">'.$langs->trans("DateModification").'</td>';
print '<td class="right"></td>';
print '</tr>'."\n";

if (is_object($driveservice)) {
	try {
		$query = "'".googleapiDriveEscapeId($parentid)."' in parents and trashed=false";
		$result = $driveservice->files->listFiles(array(
			'q' => $query,
			'fields' => 'files(id,name,mimeType,size,modifiedTime,webViewLink)',
			'orderBy' => 'folder,name',
			'pageSize' => 1000,
		));

		foreach ($result->getFiles() as $file) {
			$isfolder = ($file->getMimeType() == 'application/vnd.google-apps.folder');
			$isnativegoogletype = (!$isfolder && strpos((string) $file->getMimeType(), 'application/vnd.google-apps.') === 0);
			$fileid = $file->getId();
			$filename = $file->getName();

			print '<tr class="oddeven">';

			print '<td>';
			if ($isfolder) {
				print img_picto('', 'folder', 'class="paddingright"');
				// Drive names are attacker-controlled. dol_escape_js() alone is not enough inside an HTML
				// attribute: the browser HTML-decodes the attribute before running it as JS, so an entity
				// such as &#39; would become a real quote and break out of the JS string. The whole handler
				// is therefore also HTML-escaped. $escapeonlyhtmltags=1 is required: the default mode of
				// dol_escape_htmltag() re-emits the literal sequence "&#39;" untouched.
				$onclick = "ecmGoogleDriveNavigate('".dol_escape_js($fileid)."', '".dol_escape_js($filename)."'); return false;";
				print '<a href="#" onclick="'.dol_escape_htmltag($onclick, 0, 0, '', 1).'">';
				print dol_escape_htmltag($filename);
				print '</a>';
			} else {
				print img_mime($filename);
				print dol_escape_htmltag($filename);
			}
			print '</td>';

			print '<td class="right">';
			if (!$isfolder && $file->getSize()) {
				print dol_print_size((int) $file->getSize(), 1);
			}
			print '</td>';

			print '<td class="center">';
			if ($file->getModifiedTime()) {
				print dol_print_date(strtotime((string) $file->getModifiedTime()), 'dayhour');
			}
			print '</td>';

			print '<td class="right nowraponall">';
			if (!$isfolder && !$isnativegoogletype) {
				$downloadurl = dol_buildpath('/googleapi/core/ajax/ecmgoogledrivedownload.php', 1).'?token='.currentToken().'&fileid='.urlencode($fileid);
				print '<a class="editfielda marginleftonly" href="'.$downloadurl.'" title="'.dol_escape_htmltag($langs->trans("Download")).'">'.img_picto($langs->trans("Download"), 'download').'</a>';
			} elseif ($isnativegoogletype && $file->getWebViewLink()) {
				print '<a class="editfielda marginleftonly" href="'.dol_escape_htmltag($file->getWebViewLink()).'" target="_blank" rel="noopener noreferrer" title="'.dol_escape_htmltag($langs->trans("GoogleApiOpenInDrive")).'">'.img_picto($langs->trans("GoogleApiOpenInDrive"), 'globe').'</a>';
			}
			if ($permissiontowrite) {
				$onclick = "ecmGoogleDriveRename('".dol_escape_js($fileid)."', '".dol_escape_js($filename)."'); return false;";
				print ' <a class="editfielda marginleftonly" href="#" onclick="'.dol_escape_htmltag($onclick, 0, 0, '', 1).'" title="'.dol_escape_htmltag($langs->trans("GoogleApiRename")).'">'.img_picto($langs->trans("GoogleApiRename"), 'edit').'</a>';
			}
			if ($permissiontodelete) {
				$onclick = "ecmGoogleDriveDelete('".dol_escape_js($fileid)."', '".dol_escape_js($filename)."'); return false;";
				print ' <a class="deletefilelink marginleftonly" href="#" onclick="'.dol_escape_htmltag($onclick, 0, 0, '', 1).'" title="'.dol_escape_htmltag($langs->trans("Delete")).'">'.img_picto($langs->trans("Delete"), 'delete').'</a>';
			}
			print '</td>';

			print '</tr>'."\n";
		}
	} catch (Exception $e) {
		print '<tr><td colspan="4">'.dol_escape_htmltag($e->getMessage()).'</td></tr>'."\n";
		dol_syslog('ecmgoogledrivelist: '.$e->getMessage(), LOG_ERR);
	}
}

print '</table>'."\n";
