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

$defines = [
	'NOTOKENRENEWAL',
	'NOREQUIREMENU',
	'NOREQUIREHTML',
	'NOREQUIREAJAX',
];

include '../config.php';

$langs->loadLangs(['googleapi@googleapi']);

top_httphead('application/javascript');
?>
var ecmGoogleDriveBreadcrumb = [{id: 'root', name: '<?php echo dol_escape_js($langs->transnoentities("Home")); ?>'}];

/**
 * Escape a string so it can safely be concatenated into an HTML fragment that is later
 * injected with .html(). Drive file and folder names are attacker-controlled: anybody
 * sharing a folder with the connected account chooses its name.
 */
function ecmGoogleDriveEscapeHtml(str)
{
	return jQuery('<div>').text(str === null || typeof str === 'undefined' ? '' : str).html();
}

function ecmGoogleDriveRenderBreadcrumb()
{
	var html = '';
	for (var i = 0; i < ecmGoogleDriveBreadcrumb.length; i++) {
		if (i > 0) {
			html += ' / ';
		}
		html += '<a href="#" onclick="ecmGoogleDriveGoToBreadcrumb('+i+'); return false;">'+ecmGoogleDriveEscapeHtml(ecmGoogleDriveBreadcrumb[i].name)+'</a>';
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

function ecmGoogleDriveNotify(message, success)
{
	// jQuery.jnotify is Dolibarr's own toast notification plugin, already loaded on every page
	// by main.inc.php (see htdocs/core/lib/functions.lib.php get_htmloutput_mesg() for the same
	// calling convention: a number is an auto-dismiss delay in ms, "error"+true is a sticky error).
	if (success) {
		jQuery.jnotify(message, 3000);
	} else {
		jQuery.jnotify(message, 'error', true);
	}
}

function ecmGoogleDriveRename(fileid, currentname)
{
	var newname = prompt('<?php echo dol_escape_js($langs->transnoentities("GoogleApiNewName")); ?>', currentname);
	if (newname === null || newname === '' || newname === currentname) {
		return;
	}
	jQuery.post('<?php echo dol_buildpath('/googleapi/core/ajax/ecmgoogledriverename.php', 1); ?>', {
		token: '<?php echo currentToken(); ?>',
		fileid: fileid,
		newname: newname
	}, function(response) {
		ecmGoogleDriveNotify(response.message, response.success);
		if (response.success) {
			ecmGoogleDriveLoadList(jQuery('#ecmgdrive_folderid').val());
		}
	}, 'json');
}

function ecmGoogleDriveDelete(fileid, filename)
{
	var msgtemplate = '<?php echo dol_escape_js($langs->transnoentities("GoogleApiConfirmDeleteDriveFile")); ?>';
	if (!confirm(msgtemplate.replace('__FILENAME__', filename))) {
		return;
	}
	jQuery.post('<?php echo dol_buildpath('/googleapi/core/ajax/ecmgoogledrivedelete.php', 1); ?>', {
		token: '<?php echo currentToken(); ?>',
		fileid: fileid
	}, function(response) {
		ecmGoogleDriveNotify(response.message, response.success);
		if (response.success) {
			ecmGoogleDriveLoadList(jQuery('#ecmgdrive_folderid').val());
		}
	}, 'json');
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
