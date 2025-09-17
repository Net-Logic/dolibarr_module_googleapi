<?php
/*
 * Copyright (C) 2013-2016  Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2014-2025  Frédéric France      <frederic.france@netlogic.fr>
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
 * \file        htdocs/googleapi/tabs/usertoken.php
 * \ingroup     oauth
 * \brief       Setup page to configure oauth access to login information
 */

// Load Dolibarr environment
include '../config.php';

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/usergroups.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
//require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
dol_include_once('prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');
require_once '../lib/googleapi.lib.php';

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Provider\GoogleUser;
use Google\Service\Calendar;

// Load translation files required by the page
$langs->loadLangs(array('admin', 'oauth', 'googleapi@googleapi'));

$action = GETPOST('action', 'aZ09');
$value = GETPOST('value', 'alpha');
$varname = GETPOST('varname', 'alpha');
$id = GETPOST('id', 'int');

$object = new User($db);
$object->fetch($id);
// l'accès est réservé à l'utilisateur lui même
if ($user->id != $object->id) {
	accessforbidden();
}

/*
 * Action
 */
if ($action == 'setvalue') {
	$action = '';
}


/*
 * View
 */

// Define $urlwithroot
$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
// This is to use external domain name found into config file
$urlwithroot = $urlwithouturlroot . DOL_URL_ROOT;
// This is to use same domain name than current
//$urlwithroot=DOL_MAIN_URL_ROOT;

$form = new Form($db);

llxHeader('', $langs->trans("OauthSetup"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
// print load_fiche_titre($langs->trans('GoogleApiConfigOAuth'), $linkback, 'object_googleapi_32@googleapi');

$head = user_prepare_head($object);
if ($action == 'setcalendar') {
	$client = getGoogleApiClient($user);
	$service = new Calendar($client);
	$choices = [];
	$calendarList = $service->calendarList->listCalendarList();

	while (true) {
		foreach ($calendarList->getItems() as $calendarListEntry) {
			$choices[$calendarListEntry->getId()] = ($calendarListEntry->primary ? 'Primary : ' : '') . $calendarListEntry->summary . ' (' . $calendarListEntry->timeZone . ')';
		}
		$pageToken = $calendarList->getNextPageToken();
		if ($pageToken) {
			$optParams = array('pageToken' => $pageToken);
			$calendarList = $service->calendarList->listCalendarList($optParams);
		} else {
			break;
		}
	}
	print $form->formconfirm(
		$_SERVER['PHP_SELF'] . "?id=$object->id",
		$langs->trans("GoogleApiSetCalendarId"),
		$langs->trans("GoogleApiConfirmSetCalendarId", $object->login),
		"confirm_setcalendar",
		[
			[
				'type' => 'select',
				'label' => $langs->trans('GoogleApiCalendarId'),
				'name' => 'calendarid',
				'values' => $choices,
				'default' => $object->array_options['options_googleapi_calendarId']
			],
			[
				'type' => 'checkbox',
				'label' => $langs->trans('GoogleApiApplyUserColorToGoogleAgenda'),
				'name' => 'applycolor',
			],
		],
		0,
		1
	);
} elseif ($action == 'confirm_setcalendar') {
	$client = getGoogleApiClient($user);
	$service = new Calendar($client);
	$choices = [];
	$calendarList = $service->calendarList->listCalendarList();

	while (true) {
		foreach ($calendarList->getItems() as $calendarListEntry) {
			// var_dump($calendarListEntry);
			$choices[$calendarListEntry->id] = $calendarListEntry->timeZone;
			$entries[$calendarListEntry->id] = $calendarListEntry;
		}
		$pageToken = $calendarList->getNextPageToken();
		if ($pageToken) {
			$optParams = array('pageToken' => $pageToken);
			$calendarList = $service->calendarList->listCalendarList($optParams);
		} else {
			break;
		}
	}

	$object->array_options['options_googleapi_calendarId'] = GETPOST('calendarid', 'alpha');
	$object->array_options['options_googleapi_calendarTZ'] = $choices[GETPOST('calendarid', 'alpha')];
	$object->update($user);

	// on met la couleur de l'utilisateur sur le calendrier google
	// un rechargement de l'agenda google est nécessaire pour voir le changement...
	if (GETPOST('applycolor') == 'on' && !empty($object->color)) {
		$calendarListEntry = $entries[GETPOST('calendarid', 'alpha')];
		$calendarListEntry->setBackgroundColor('#' . $object->color);
		$updatedCalendarListEntry = $service->calendarList->update(GETPOST('calendarid', 'alpha'), $calendarListEntry, array("colorRgbFormat" => true));
	}
}

print dol_get_fiche_head($head, 'googleapitoken', $langs->trans("User"), -1, 'user');



print $langs->trans("OAuthSetupForLogin") . "<br><br>\n";

$OAUTH_SERVICENAME = 'GoogleApi';
$urltorenew = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1) . '?backtourl=' . urlencode(dol_buildpath('/googleapi/tabs/usertoken.php', 1) . '?id=' . (int) $object->id);
$urltodelete = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1) . '?action=delete&token=' . newToken() . '&backtourl=' . urlencode(dol_buildpath('/googleapi/tabs/usertoken.php', 1) . '?id=' . (int) $object->id);
$urltocheckperms = 'https://security.google.com/settings/security/permissions';

// Token
$token = retrieveAccessToken('GoogleApi', $object->id);
$tokenrefreshbackup = retrieveRefreshTokenBackup('GoogleApi', $object->id);
// var_dump($token, $tokenrefreshbackup);
// Set other properties
$refreshtoken = false;
$expiredat = '';

// Is token expired or will token expire in the next 30 seconds
if (is_object($token)) {
	$expire = $token->hasExpired();
	$isgoingtoexpire = (time() > ($token->getExpires() - 30));
	if ($isgoingtoexpire) {
		$provider = new Google([
			'clientId' => getDolGlobalString('OAUTH_GOOGLEAPI_ID'),
			'clientSecret' => getDolGlobalString('OAUTH_GOOGLEAPI_SECRET'),
			'redirectUri' => dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2),
		]);
		$grant = new RefreshToken();
		$token = $provider->getAccessToken($grant, ['refresh_token' => $tokenrefreshbackup]);
		$expire = $token->hasExpired();
		storeAccessToken('GoogleApi', $token, $tokenrefreshbackup, $object->id);
		setEventMessages($langs->trans('NewTokenStored'), null, 'mesgs'); // Stored into object managed by class DoliStorage so into table oauth_token
	}
	$refreshtoken = $token->getRefreshToken();

	$endoflife = $token->getExpires();
	$expiredat = dol_print_date($endoflife, "dayhour", 'tzuser');
}

print '<table class="noborder" width="100%">' . PHP_EOL;

print '<tr class="liste_titre">';
print '<th class="titlefieldcreate nowrap">' . $langs->trans('Parameters') . '</th>';
print '<th></th>';
print '<th></th>';
print "</tr>\n";

print '<tr class="oddeven">';
print '<td>';
print $langs->trans("OAuthIDSecret") . '</td>';
print '<td>';
print $langs->trans("SeePreviousTab");
print '</td>';
print '<td>';
print '</td>';
print '</tr>' . PHP_EOL;

print '<tr class="oddeven">';
print '<td>';
print $langs->trans("IsTokenGenerated");
print '</td>';
print '<td>';
if (is_object($token)) {
	print $langs->trans("HasAccessToken");
} else {
	print $langs->trans("NoAccessToken");
}
print '</td>';
print '<td>';
// Links to delete/checks token
if (is_object($token)) {
	//test on $storage->hasAccessToken($OAUTH_SERVICENAME) ?
	print '<a class="button" href="' . $urltodelete . '">' . $langs->trans('DeleteAccess') . '</a><br>';
}
// Request remote token
print '<a class="button" href="' . $urltorenew . '">' . $langs->trans('RequestAccess') . '</a><br>';
// Check remote access
print '<br>' . $langs->trans("ToCheckDeleteTokenOnProvider", $OAUTH_SERVICENAME) . ': <a href="' . $urltocheckperms . '" target="_' . strtolower($OAUTH_SERVICENAME) . '">' . $urltocheckperms . '</a>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>';
print $langs->trans("Token") . '</td>';
print '<td colspan="2">';
if (is_object($token)) {
	//var_dump($token);
	print dol_trunc($token->getToken(), 40, 'middle') . '<br>';
}
print '</td>';
print '</tr>' . PHP_EOL;

if (is_object($token)) {
	// Token refresh
	print '<tr class="oddeven">';
	print '<td>';
	print $langs->trans("TOKEN_REFRESH") . '</td>';
	print '<td colspan="2">';
	print dol_trunc($refreshtoken, 30, 'middle');
	print '</td>';
	print '</tr>';

	// Token refresh
	print '<tr class="oddeven">';
	print '<td>';
	print $langs->trans("TOKEN_REFRESH_BACKUP") . '</td>';
	print '<td colspan="2">';
	print dol_trunc($tokenrefreshbackup, 30, 'middle');
	print '</td>';
	print '</tr>';

	// Token expired
	print '<tr class="oddeven">';
	print '<td>';
	print $langs->trans("TOKEN_EXPIRED") . '</td>';
	print '<td colspan="2">';
	print yn($expire);
	print '</td>';
	print '</tr>';

	// Token expired at
	print '<tr class="oddeven">';
	print '<td>';
	print $langs->trans("TOKEN_EXPIRE_AT") . '</td>';
	print '<td colspan="2">';
	print $expiredat;
	print '</td>';
	print '</tr>';
}

print '</table>';

print dol_get_fiche_end();

if (is_object($token)) {
	print '<div class="tabsAction">';
	print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&amp;action=setcalendar">' . $langs->trans("GoogleApiSetCalendarId") . '</a></div>';
	print "</div>\n";
}


if (is_object($token)) {
	$provider = new Google([
		'clientId' => getDolGlobalString('OAUTH_GOOGLEAPI_ID'),
		'clientSecret' => getDolGlobalString('OAUTH_GOOGLEAPI_SECRET'),
		'redirectUri' => dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2),
		//'hostedDomain' => 'example.com', // optional; used to restrict access to users on your G Suite/Google Apps for Business accounts
		'accessType' => 'offline',
	]);

	$owner = $provider->getResourceOwner($token);
	// var_dump($owner->toArray());
	if (!empty($owner)) {
		$object->array_options['options_googleapi_Id'] = $owner->getId();
		$object->array_options['options_googleapi_email'] = $owner->toArray()['email'];
		$object->update($user, 1);
	}
}

// End of page
llxFooter();
$db->close();
