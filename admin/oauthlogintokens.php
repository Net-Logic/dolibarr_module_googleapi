<?php
/* Copyright (C) 2013-2016  Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2014-2022  Frédéric France      <frederic.france@netlogic.fr>
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
 * \file        htdocs/admin/oauthlogintokens.php
 * \ingroup     oauth
 * \brief       Setup page to configure oauth access to login information
 */

// Load Dolibarr environment
include '../config.php';

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
//require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
dol_include_once('prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');
require_once '../lib/googleapi.lib.php';

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Provider\GoogleUser;

// Load translation files required by the page
$langs->loadLangs(array('admin', 'oauth', 'googleapi@googleapi'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$value = GETPOST('value', 'alpha');
$varname = GETPOST('varname', 'alpha');

$key = array(
	'OAUTH_GOOGLEAPI_NAME',
	'OAUTH_GOOGLEAPI_ID',
	'OAUTH_GOOGLEAPI_SECRET',
	'OAUTH_GOOGLEAPI_DESC',
);


/*
 * Action
 */

if ($action == 'setconst' && $user->admin) {
	$error = 0;
	$db->begin();
	foreach ($_POST['setupdriver'] as $setupconst) {
		//print '<pre>'.print_r($setupconst, true).'</pre>';
		$result = dolibarr_set_const($db, $setupconst['varname'], $setupconst['value'], 'chaine', 0, '', $conf->entity);
		if (!$result > 0) {
			$error++;
		}
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null);
	} else {
		$db->rollback();
		dol_print_error($db);
	}
	$action = '';
}

if ($action == 'setvalue' && $user->admin) {
	$db->begin();

	$result = dolibarr_set_const($db, $varname, $value, 'chaine', 0, '', $conf->entity);
	if (!$result > 0) {
		$error++;
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null);
	} else {
		$db->rollback();
		dol_print_error($db);
	}
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
print load_fiche_titre($langs->trans('GoogleApiConfigOAuth'), $linkback, 'object_googleapi_32@googleapi');

$head = googleapiAdminPrepareHead();

print dol_get_fiche_head($head, 'tokengeneration', '', -1, 'technic');


if ($user->admin) {
	print $langs->trans("OAuthSetupForLogin") . "<br><br>\n";

	$OAUTH_SERVICENAME = 'GoogleApi';
	$urltorenew = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1) . '?backtourl=' . urlencode(dol_buildpath('/googleapi/admin/oauthlogintokens.php', 1));
	$urltodelete = dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1) . '?action=delete&token=' . $_SESSION['newtoken'] . '&backtourl=' . urlencode(dol_buildpath('/googleapi/admin/oauthlogintokens.php', 1));
	$urltocheckperms = 'https://security.google.com/settings/security/permissions';


	// Show value of token
	$token = null;
	// Token

	$token = retrieveAccessToken('GoogleApi', $user->id);
	$tokenrefreshbackup = retrieveRefreshTokenBackup('GoogleApi', $user->id);
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
				'clientId'     => getDolGlobalString('OAUTH_GOOGLEAPI_ID'),
				'clientSecret' => getDolGlobalString('OAUTH_GOOGLEAPI_SECRET'),
				'redirectUri'  => dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2),
			]);
			$grant = new RefreshToken();
			$token = $provider->getAccessToken($grant, ['refresh_token' => $tokenrefreshbackup]);
			$expire = $token->hasExpired();
			storeAccessToken('GoogleApi', $token, $tokenrefreshbackup, $user->id);
			setEventMessages($langs->trans('NewTokenStored'), null, 'mesgs'); // Stored into object managed by class DoliStorage so into table oauth_token
		}
		$refreshtoken = $token->getRefreshToken();

		$endoflife = $token->getExpires();
		$expiredat = dol_print_date($endoflife, "dayhour");
	}

	print '<form method="post" action="' . $_SERVER["PHP_SELF"] . '" autocomplete="off">';
	print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
	print '<input type="hidden" name="action" value="setconst">';

	print '<table class="noborder" width="100%">' . PHP_EOL;

	print '<tr class="liste_titre">';
	print '<th class="titlefieldcreate nowrap">' . $langs->trans($key[0]) . '</th>';
	print '<th></th>';
	print '<th></th>';
	print "</tr>\n";

	print '<tr class="oddeven">';
	print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
	//var_dump($key);
	print $langs->trans("OAuthIDSecret") . '</td>';
	print '<td>';
	print $langs->trans("SeePreviousTab");
	print '</td>';
	print '<td>';
	print '</td>';
	print '</tr>' . PHP_EOL;

	print '<tr class="oddeven">';
	print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
	//var_dump($key);
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
	print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
	//var_dump($key);
	print $langs->trans("Token") . '</td>';
	print '<td colspan="2">';
	if (is_object($token)) {
		//var_dump($token);
		print dol_trunc($token->getToken(), 30, 'middle') . '<br>';
		//print 'Refresh: '.$token->getRefreshToken().'<br>';
		//print 'EndOfLife: '.dol_print_date($token->getExpires(), "dayhour").'<br>';
		//var_dump($token->getExtraParams());
		/*print '<br>Extra: <br><textarea class="quatrevingtpercent">';
		print ''.join(',',$token->getExtraParams());
		print '</textarea>';*/
	}
	print '</td>';
	print '</tr>' . PHP_EOL;

	if (is_object($token)) {
		// Token refresh
		print '<tr class="oddeven">';
		print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
		//var_dump($key);
		print $langs->trans("TOKEN_REFRESH") . '</td>';
		print '<td colspan="2">';
		print dol_trunc($refreshtoken, 30, 'middle');
		print '</td>';
		print '</tr>';

		// Token refresh
		print '<tr class="oddeven">';
		print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
		print $langs->trans("TOKEN_REFRESH_BACKUP") . '</td>';
		print '<td colspan="2">';
		print dol_trunc($tokenrefreshbackup, 30, 'middle');
		print '</td>';
		print '</tr>';

		// Token expired
		print '<tr class="oddeven">';
		print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
		print $langs->trans("TOKEN_EXPIRED") . '</td>';
		print '<td colspan="2">';
		print yn($expire);
		print '</td>';
		print '</tr>';

		// Token expired at
		print '<tr class="oddeven">';
		print '<td' . (!empty($key['required']) ? ' class="required"' : '') . '>';
		print $langs->trans("TOKEN_EXPIRE_AT") . '</td>';
		print '<td colspan="2">';
		print $expiredat;
		print ' UTC</td>';
		print '</tr>';
	}

	print '</table>';

	print '</form>';
}

print dol_get_fiche_end();


if (is_object($token)) {
	$provider = new Google([
		'clientId'     => getDolGlobalString('OAUTH_GOOGLEAPI_ID'),
		'clientSecret' => getDolGlobalString('OAUTH_GOOGLEAPI_SECRET'),
		'redirectUri'  => dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2),
		//'hostedDomain' => 'example.com', // optional; used to restrict access to users on your G Suite/Google Apps for Business accounts
		'accessType'   => 'offline',
	]);
	$owner = $provider->getResourceOwner($token);
	//var_dump($owner->toArray());
	if (!empty($owner)) {
		$user->array_options['options_googleapi_Id'] = $owner->getId();
		$user->array_options['options_googleapi_email'] = $owner->toArray()['email'];
		$user->update($user, 1);
	}

	// $client = getGoogleApiClient($user);

	// $gdocs = new Google\Service\Docs\
	// $drive = new \Google\Service\Drive($client);
	// $params = [];
	// $drivelist = $drive->files->listFiles($params);
	// var_dump($drivelist);

	// $service = new \Google\Service\Sheets($client);

	// try {
	// 	$spreadsheetId = '1Y5dzd0kIL8B-g5okraVnV2c-2IMao3axDSguSFzOxqM';
	// 	$range = 'A1:E1';
	// 	$response = $service->spreadsheets_values->get($spreadsheetId, $range);

	// 	var_dump($response);
	// 	//$values = $response->getValues();

	// 	// if (empty($values)) {
	// 	// 	print "No data found.\n";
	// 	// } else {
	// 	// 	print "Name, Major:\n";
	// 	// 	foreach ($values as $row) {
	// 	// 		// Print columns A and E, which correspond to indices 0 and 4.
	// 	// 		printf("%s, %s\n", $row[0], $row[4]);
	// 	// 	}
	// 	// }
	// } catch (Exception $e) {
	// 	// TODO(developer) - handle error appropriately
	// 	echo 'Message: ' .$e->getMessage();
	// }
}

// End of page
llxFooter();
$db->close();
