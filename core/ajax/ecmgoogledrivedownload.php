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
 * \file    googleapi/core/ajax/ecmgoogledrivedownload.php
 * \ingroup googleapi
 * \brief   Streams a Google Drive file to the browser
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

if (!$user->hasRight('googleapi', 'read')) {
	accessforbidden();
}

$fileid = GETPOST('fileid', 'alpha');
if (empty($fileid)) {
	http_response_code(400);
	print 'Missing fileid';
	exit;
}

$driveservice = getGoogleDriveService($user);
if (!is_object($driveservice)) {
	http_response_code(403);
	print 'Not connected to Google Drive';
	exit;
}

try {
	$metadata = $driveservice->files->get($fileid, ['fields' => 'name,mimeType,size']);
	if (strpos((string) $metadata->getMimeType(), 'application/vnd.google-apps.') === 0) {
		// Native Google file (Docs/Sheets/Slides/...) has no direct binary content to stream
		http_response_code(400);
		print 'This file type cannot be downloaded directly, open it in Google Drive instead';
		exit;
	}

	$response = $driveservice->files->get($fileid, ['alt' => 'media']);
	$body = $response->getBody();

	top_httphead($metadata->getMimeType() ? $metadata->getMimeType() : 'application/octet-stream');
	header('Content-Disposition: attachment; filename="' . dol_sanitizeFileName($metadata->getName()) . '"');
	if ($body->getSize() !== null) {
		header('Content-Length: ' . $body->getSize());
	}

	// Stream in fixed-size chunks instead of loading the whole file into a single PHP string
	// (getContents() would double peak memory and hold up the first byte until fully read).
	$chunksizebytes = 65536;
	while (!$body->eof()) {
		print $body->read($chunksizebytes);
		flush();
	}
} catch (Exception $e) {
	dol_syslog('ecmgoogledrivedownload: ' . $e->getMessage(), LOG_ERR);
	http_response_code(500);
	print dol_escape_htmltag($e->getMessage());
}
