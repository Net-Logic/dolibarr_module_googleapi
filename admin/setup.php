<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2019-2022  Frédéric France         <frederic.france@netlogic.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/modulebuilder/template/admin/setup.php
 * \ingroup googleapi
 * \brief   GoogleApi setup page.
 */

// Load Dolibarr environment
include '../config.php';
/**
 * @var DoliDB $db
 * @var Translate $langs
 */

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
dol_include_once('/prune/vendor/autoload.php');
dol_include_once('/prune/lib/prune.lib.php');
require_once '../lib/googleapi.lib.php';

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Grant\RefreshToken;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;

// Translations
$langs->loadLangs(['errors', 'admin', 'oauth', 'googleapi@googleapi']);

// Access control
if (! $user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Define $urlwithroot
$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
$urlwithouturlroot = str_replace('http://', 'https://', $urlwithouturlroot);
// This is to use external domain name found into config file
$urlwithroot = $urlwithouturlroot . DOL_URL_ROOT;
//$urlwithroot = DOL_MAIN_URL_ROOT;               // This is to use same domain name than current

$arrayofparameters = [
	'OAUTH_GOOGLEAPI_ID' => [
		'css' => 'minwidth500',
		'type' => 'text',
		'enabled' => 1,
	],
	'OAUTH_GOOGLEAPI_SECRET' => [
		'css' => 'minwidth500',
		'type' => 'password',
		'enabled' => 1,
	],
	'OAUTH_GOOGLEAPI_URI' => [
		'css' => 'minwidth500',
		'default' => $urlwithroot . dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1),
	],
	'GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER' => [
		'css' => 'minwidth200',
		'type' => 'text',
		'default' => 'Dolibarr',
	],
	// 'GOOGLEAPI_MYPARAM1' => [
	//     'css' => 'minwidth500',
	//     'type' => 'text',
	//     'enabled' => 1,
	// ],
	// 'GOOGLEAPI_MYPARAM2' => [
	//     'css' => 'minwidth500',
	//     'type' => 'text',
	//     'enabled' => 1,
	// ],
];

// Paramètres ON/OFF GOOGLEAPI_ est rajouté au paramètre
$modules = [
	'GOOGLEAPI_ENABLE_PUSH_ME_EVENTS' => 'GoogleApiEnablePushMeEvents',
	'GOOGLEAPI_ENABLE_PUSH_ME_MESSAGES' => 'GoogleApiEnablePushMeMessages',
	'GOOGLEAPI_ENABLE_PUSH_ME_CONTACTS' => 'GoogleApiEnablePushMeContacts',
	'GOOGLEAPI_ENABLE_PUSH_ME_DOCS' => 'GoogleApiEnablePushMeDocs',
	'GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG' => 'GoogleApiEnableExtrafieldsDebug',
	'GOOGLEAPI_ENABLE_DEVELOPPER_MODE' => 'GoogleApiEnableDevelopperMode',
	// tweak dolibarr
	'CHECKLASTVERSION_EXTERNALMODULE' => 'CHECKLASTVERSION_EXTERNALMODULE',
];

$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
if (empty($googleapicontexts)) {
	// set default
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode(['standard' => true]), 'chaine', 0, '', $conf->entity);
	$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
}

$googleapidrivesyncobjects = json_decode(getDolGlobalString('GOOGLEAPI_DRIVE_SYNC_OBJECTS', '{}'), true);
if (!is_array($googleapidrivesyncobjects)) {
	$googleapidrivesyncobjects = [];
}

/*
 * Actions
 */
foreach ($modules as $const => $desc) {
	if ($action == 'activate_' . strtolower($const)) {
		dolibarr_set_const($db, $const, "1", 'chaine', 0, '', $conf->entity);
	}
	if ($action == 'disable_' . strtolower($const)) {
		dolibarr_del_const($db, $const, $conf->entity);
		//header("Location: ".$_SERVER["PHP_SELF"]);
		//exit;
	}
}
foreach ($googleapicontexts as $constant => $value) {
	if ($action == 'contextenable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = true;
	}
	if ($action == 'contextdisable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = false;
	}
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode($googleapicontexts), 'chaine', 0, '', $conf->entity);
}
foreach ($googleapidrivesyncobjects as $constant => $value) {
	if ($action == 'drivesyncenable_' . strtolower($constant)) {
		$googleapidrivesyncobjects[$constant] = true;
	}
	if ($action == 'drivesyncdisable_' . strtolower($constant)) {
		$googleapidrivesyncobjects[$constant] = false;
	}
	dolibarr_set_const($db, 'GOOGLEAPI_DRIVE_SYNC_OBJECTS', json_encode($googleapidrivesyncobjects), 'chaine', 0, '', $conf->entity);
}
if ($action == 'update') {
	$error = 0;
	$db->begin();
	foreach ($arrayofparameters as $key => $val) {
		$result = dolibarr_set_const($db, $key, GETPOST($key, 'alpha'), 'chaine', 0, '', $conf->entity);
		if ($result < 0) {
			$error++;
			break;
		}
	}
	if (! $error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("SetupNotSaved"), null, 'errors');
	}
}

/*
 * View
 */

llxHeader();

$form = new Form($db);

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">';
$linkback .= $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans('ConfigOAuth'), $linkback, 'object_googleapi_32@googleapi');

$head = googleapiAdminPrepareHead();

print dol_get_fiche_head($head, 'settings', '', -1, 'technic');

if ($action == 'edit') {
	print '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';

	//print $langs->trans("ListOfSupportedOauthProviders").'<br><br>';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans("Parameter") . '</td><td>' . $langs->trans("Value") . '</td></tr>';

	// Api Name
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans('OAUTH_GOOGLEAPI_NAME') . '</td>';
	print '<td>' . $langs->trans('OAUTH_GOOGLEAPI_DESC') . '</td>';
	print '</tr>';

	foreach ($arrayofparameters as $key => $val) {
		print '<tr class="oddeven">';
		print '<td>';
		$tooltiphelp = (($langs->trans($key . 'Tooltip') != $key . 'Tooltip') ? $langs->trans($key . 'Tooltip') : '');
		print $form->textwithpicto($langs->trans($key), $tooltiphelp);
		$type = empty($val['type']) ? 'text' : $val['type'];
		$value = ! empty($conf->global->$key) ? $conf->global->$key : (isset($val['default']) ? $val['default'] : '');
		print '</td>';
		print '<td><input name="' . $key . '" type="' . $type . '" class="flat ' . (empty($val['css']) ? 'minwidth200' : $val['css']) . '" value="' . $value . '"></td>';
		print '</tr>';
	}
	print '</table>';

	print '<br><div class="center">';
	print '<input class="button" type="submit" value="' . $langs->trans("Save") . '">';
	print '</div>';

	print '</form>';
	print '<br>';
} else {
	print '<table class="noborder centpercent">';

	print '<tr class="liste_titre">';
	print '<td class="titlefield">' . $langs->trans("Parameter") . '</td>';
	print '<td>' . $langs->trans("Value") . '</td></tr>';

	// Api Name
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans('OAUTH_GOOGLEAPI_NAME') . '</td>';
	print '<td>' . $langs->trans('OAUTH_GOOGLEAPI_DESC') . '</td>';
	print '</tr>';

	foreach ($arrayofparameters as $key => $val) {
		print '<tr class="oddeven"><td>';
		$tooltiphelp = (($langs->trans($key . 'Tooltip') != $key . 'Tooltip') ? $langs->trans($key . 'Tooltip') : '');
		print $form->textwithpicto($langs->trans($key), $tooltiphelp);
		print '</td><td>';
		$value = $conf->global->$key;
		if (isset($val['type']) && $val['type'] == 'password') {
			$value = preg_replace('/./i', '*', $value);
		}
		print $value;
		print '</td></tr>';
	}

	print '</table>';

	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit">' . $langs->trans("Modify") . '</a>';
	print '</div>';
	print '<table class="noborder" width="100%">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("Paramètres Divers") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	// Modules
	foreach ($modules as $constant => $desc) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans($desc) . '</td>';
		print '<td align="center" width="100">';
		$value = (isset($conf->global->$constant) ? $conf->global->$constant : 0);
		if ($value == 0) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=activate_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} elseif ($value == 1) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=disable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print '</table>' . PHP_EOL;
	print '<br>' . PHP_EOL;

		// Contexts
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("GoogleApiSendContexts") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	foreach ($googleapicontexts as $constant => $value) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans('GoogleApiEnabledContext', $constant) . '</td>';
		print '<td align="center" width="100">';
		// $value = (isset($conf->global->$constant) ? $conf->global->$constant : 0);
		if (!$value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextenable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} elseif ($value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextdisable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print "</table>\n";
	print "<br>\n";

	// Drive sync per object type
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("GoogleApiDriveSyncObjects") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	if (empty($googleapidrivesyncobjects)) {
		print '<tr class="oddeven"><td colspan="2">' . $langs->trans("GoogleApiDriveSyncObjectsEmpty") . '</td></tr>';
	}
	foreach ($googleapidrivesyncobjects as $constant => $value) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans('GoogleApiDriveSyncEnabledObject', $constant) . '</td>';
		print '<td align="center" width="100">';
		if (!$value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=drivesyncenable_' . strtolower($constant) . '&amp;token=' . newToken() . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} else {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=drivesyncdisable_' . strtolower($constant) . '&amp;token=' . newToken() . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print "</table>\n";
	print "<br>\n";
}

print dol_get_fiche_end();

// TESTS

$urlfornotification = dol_buildpath('/googleapi/notifications.php', 2);
//print $urlfornotification;

// $filesystemAdapter = new Local(DOL_DATA_ROOT . '/');
// $filesystem = new Filesystem($filesystemAdapter);
// $cache = new FilesystemCachePool($filesystem);

$client = getGoogleApiClient($user);
//$client->setApplicationName("Client_Library_Examples");
//$client->setDeveloperKey("YOUR_APP_KEY");
//$client->setAccessToken($token->getToken());
//$client->setCache($cache);

// $service = new Google\Service\Calendar($client);
// $channelId = '2a99bfdb-e883-44b1-a0fd-4c4e8f09a6c8';
// $resourceId = 'FRAjW44r35gCeoSyUNoR1gNmamM';
// $channel = new Google\Service\Calendar\Channel($client);
// $channel->setId($channelId);
// $channel->setResourceId($resourceId);
// $channel->setType('web_hook');
// $channel->setAddress($urlfornotification);
// // try {
// // 	// création
// // 	//var_dump($calendarList = $service->events->watch('ecoloboutique@gmail.com', $channel));
// // } catch (Exception $e) {
// // 	print $e->getMessage();
// // }
// try {
// 	// suppression
// 	var_dump($calendarList = $service->channels->stop($channel));
// } catch (Exception $e) {
// 	print $e->getMessage();
// }

// https://developers.google.com/resources/api-libraries/documentation/calendar/v3/php/latest/class-Google_Service_Calendar_Channel.html
// https://stackoverflow.com/questions/24080116/google-calendar-api-stop-watching-events
// dol_include_once('/googleapi/class/googleapi.class.php');
// $gapi = new GoogleApi($db);
// $gapi->checkExpiredSheduledWatch();


// End of page
llxFooter();
$db->close();
