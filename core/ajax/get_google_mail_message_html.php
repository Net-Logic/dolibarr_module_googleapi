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
require_once __DIR__ . '/../../lib/googleapi.lib.php';

/**
 * @global $db 			DoliDB
 * @global $user 		User
 * @global $mysoc 		Societe
 * @global $langs 		Translate
 * @global $conf 		Conf
 * @global $hookmanager HookManager
 */
global $db, $user, $mysoc, $langs, $conf, $hookmanager;

if ($user->socid > 0) {
	print 'Unauthorized';
	exit();
}

$langs->load('googleapi@googleapi');
$messageId = GETPOST('message_id');


$result = getGoogleMailMessageAndBody($messageId);
/**
 * @var \Google\Service\Gmail\Message|null $message
 * @var array $body
 */
$message = $result['message'] ?? null;
$body = $result['body'] ?? [];
if (!$message) {
	print json_encode('Error retrieving message');
	exit();
}


$headers = $message->getPayload()->getHeaders();

$subject = $from = $to = $date = '';
foreach ($headers as $header) {
	if (in_array($header->getName(), ['Subject', 'From', 'To', 'Date'])) {
		$headerName = mb_strtolower($header->getName());
		$$headerName = $header->getValue();
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>GMail Message</title>
</head>
<body>
	<?= $body['html'] ?: $body['plain'] ?>
</body>
</html>

<?php
$db->close();
