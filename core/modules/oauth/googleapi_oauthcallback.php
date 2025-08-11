<?php
/*
 * Copyright (C) 2015-2022  Frederic France      <frederic.france@free.fr>
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       htdocs/core/modules/oauth/google_oauthcallback.php
 *      \ingroup    oauth
 *      \brief      Page to get oauth callback
 */

include '../../../config.php';
dol_include_once('prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');
require_once '../../../lib/googleapi.lib.php';

use League\OAuth2\Client\Provider\Google;

// Define $urlwithroot
$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
$urlwithroot = $urlwithouturlroot . DOL_URL_ROOT; // This is to use external domain name found into config file
//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current


$action = GETPOST('action', 'aZ09');
$backtourl = GETPOST('backtourl', 'alpha');

$langs->load("oauth");

/*
 * Actions
 */

if (GETPOSTISSET('error') && !empty($user->id)) {
	setEventMessages(GETPOST('error', 'restricthtml'), null, 'errors');
	setEventMessages(GETPOST('error_description', 'restricthtml'), null, 'errors');

	header('Location: ' . dol_buildpath('/googleapi/tabs/usertoken.php', 2) . '?id=' . $user->id);
	exit();
}
if ($action == 'delete' && !empty($user->id)) {
	clearToken('GoogleApi', $user->id);

	setEventMessages($langs->trans('TokenDeleted'), null, 'mesgs');

	header('Location: ' . $backtourl);
	exit();
}

$provider = new Google([
	'clientId' => $conf->global->OAUTH_GOOGLEAPI_ID ?? '',
	'clientSecret' => $conf->global->OAUTH_GOOGLEAPI_SECRET ?? '',
	'redirectUri' => dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2),
	//'hostedDomain' => 'example.com', // optional; used to restrict access to users on your G Suite/Google Apps for Business accounts
	'accessType'   => 'offline',
]);
//print dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2);exit;
if (!empty($_GET['error'])) {
	// Got an error, probably user denied access
	exit('Got error: ' . htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'));
} elseif (empty($_GET['code'])) {
	$_SESSION["backtourlsavedbeforeoauthjump"] = $backtourl;
	// If we don't have an authorization code then get one
	// https://developers.google.com/identity/protocols/oauth2/scopes
	$scopes = [
		'https://www.googleapis.com/auth/calendar',
		'https://mail.google.com/',
	];
	// https://developers.google.com/identity/protocols/oauth2/scopes#docs
	$scopes[] = 'https://www.googleapis.com/auth/documents';
	$scopes[] = 'https://www.googleapis.com/auth/drive';
	$scopes[] = 'https://www.googleapis.com/auth/spreadsheets';
	$authUrl = $provider->getAuthorizationUrl([
		'prompt' => 'consent',
		'access_type' => 'offline',
		'scope' => $scopes,
	]);
	$_SESSION['oauth2state'] = $provider->getState();
	header('Location: ' . $authUrl);
	exit;
} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
	// State is invalid, possible CSRF attack in progress
	unset($_SESSION['oauth2state']);
	exit('Invalid state');
} else {
	// Try to get an access token (using the authorization code grant)
	$token = $provider->getAccessToken('authorization_code', [
		'code' => $_GET['code']
	]);
	// Optional: Now you have a token you can look up a users profile data
	// try {
	// 	// We got an access token, let's now get the owner details
	// 	$ownerDetails = $provider->getResourceOwner($token);
	// 	// Use these details to create a new profile
	// 	printf('Hello %s!', $ownerDetails->getFirstName());
	// } catch (Exception $e) {
	// 	// Failed to get user details
	// 	exit('Something went wrong: ' . $e->getMessage());
	// }
	$refreshtoken = $token->getRefreshToken();
	$tokenrefreshbackup = retrieveRefreshTokenBackup('GoogleApi', $user->id);
	if (empty($refreshtoken) && !empty($tokenrefreshbackup)) {
		$refreshtoken = $tokenrefreshbackup;
	}
	storeAccessToken('GoogleApi', $token, $refreshtoken, $user->id);
	setEventMessages($langs->trans('NewTokenStored'), null, 'mesgs'); // Stored into object managed by class DoliStorage so into table oauth_token

	$backtourl = $_SESSION["backtourlsavedbeforeoauthjump"];
	unset($_SESSION["backtourlsavedbeforeoauthjump"]);

	header('Location: ' . $backtourl);
	exit();
}


/*
 * View
 */

// No view at all, just actions

$db->close();
