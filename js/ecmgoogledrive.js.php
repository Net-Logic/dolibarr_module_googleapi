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
 * \file    googleapi/js/ecmgoogledrive.js.php
 * \ingroup googleapi
 * \brief   JS glue for the Google Drive ECM tab
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

include '../config.php';

$langs->loadLangs(array('googleapi@googleapi'));

top_httphead('application/javascript');
?>
var ecmGoogleDriveBreadcrumb = [{id: 'root', name: '<?php echo dol_escape_js($langs->trans("Home")); ?>'}];

function ecmGoogleDriveRenderBreadcrumb()
{
	var html = '';
	for (var i = 0; i < ecmGoogleDriveBreadcrumb.length; i++) {
		if (i > 0) {
			html += ' / ';
		}
		html += '<a href="#" onclick="ecmGoogleDriveGoToBreadcrumb('+i+'); return false;">'+ecmGoogleDriveBreadcrumb[i].name+'</a>';
	}
	jQuery('#ecmgdrive-breadcrumb').html(html);
}

function ecmGoogleDriveGoToBreadcrumb(index)
{
	var entry = ecmGoogleDriveBreadcrumb[index];
	ecmGoogleDriveBreadcrumb = ecmGoogleDriveBreadcrumb.slice(0, index + 1);
	jQuery('#ecmgdrive_folderid').val(entry.id);
	ecmGoogleDriveLoadList(entry.id);
}

function ecmGoogleDriveNavigate(folderid, foldername)
{
	ecmGoogleDriveBreadcrumb.push({id: folderid, name: foldername});
	jQuery('#ecmgdrive_folderid').val(folderid);
	ecmGoogleDriveLoadList(folderid);
}

function ecmGoogleDriveLoadList(folderid)
{
	ecmGoogleDriveRenderBreadcrumb();
	jQuery('#ecmgdrive-filelist').html('<?php echo dol_escape_js($langs->trans("PleaseBePatient")); ?>');
	jQuery.get('<?php echo dol_buildpath('/googleapi/core/ajax/ecmgoogledrivelist.php', 1); ?>', {dir: folderid, token: '<?php echo currentToken(); ?>'}, function(data) {
		jQuery('#ecmgdrive-filelist').html(data);
	});
}

function ecmGoogleDriveRename(fileid, currentname)
{
	var newname = prompt('<?php echo dol_escape_js($langs->trans("GoogleApiNewName")); ?>', currentname);
	if (newname === null || newname === '' || newname === currentname) {
		return;
	}
	jQuery.post('<?php echo dol_buildpath('/googleapi/ecmgoogledrive.php', 1); ?>', {
		action: 'renamedrivefile',
		token: '<?php echo newToken(); ?>',
		fileid: fileid,
		newname: newname
	}, function() {
		ecmGoogleDriveLoadList(jQuery('#ecmgdrive_folderid').val());
	});
}

function ecmGoogleDriveDelete(fileid, filename)
{
	var msgtemplate = '<?php echo dol_escape_js($langs->trans("GoogleApiConfirmDeleteDriveFile")); ?>';
	if (!confirm(msgtemplate.replace('__FILENAME__', filename))) {
		return;
	}
	jQuery.post('<?php echo dol_buildpath('/googleapi/ecmgoogledrive.php', 1); ?>', {
		action: 'deletedrivefile',
		token: '<?php echo newToken(); ?>',
		fileid: fileid
	}, function() {
		ecmGoogleDriveLoadList(jQuery('#ecmgdrive_folderid').val());
	});
}

jQuery(document).ready(function() {
	// Load the file list first, and isolate the folder tree init: a failure of the jqueryFileTree
	// plugin (not loaded, error, ...) must never prevent the file list from being displayed.
	ecmGoogleDriveLoadList('root');

	try {
		jQuery('#filetree').fileTree(
			{
				root: 'root/',
				script: '<?php echo dol_buildpath('/googleapi/core/ajax/ecmgoogledrivetree.php', 1); ?>?token=<?php echo currentToken(); ?>',
				folderEvent: 'click',
				multiFolder: false
			},
			function(file) {
				// Files are not shown in the left tree (folders only): nothing to do here.
			}
		);
	} catch (e) {
		console.error('ecmgoogledrive: unable to initialize the folder tree', e);
	}
});
