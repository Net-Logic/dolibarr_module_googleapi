<?php
/*
 * Copyright (C) 2007-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2025  Frédéric France         <frederic.france@netlogic.fr>
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
 *  \file       htdocs/custom/googleapi/tabs/googleapi_emails.php
 *  \ingroup    googleapi
 *  \brief      List page googleapi emails
 */


// Load Dolibarr environment
include './config.php';

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
//dol_include_once('/googleapi/class/googleapi.class.php');

/**
 * @global $db 			DoliDB
 * @global $user 		User
 * @global $mysoc 		Societe
 * @global $langs 		Translate
 * @global $conf 		Conf
 * @global $hookmanager HookManager
 */
global $db, $user, $mysoc, $langs, $conf, $hookmanager;

// Load translation files required by the page
$langs->loadLangs(["googleapi@googleapi"]);

// The action 'add', 'create', 'edit', 'update', 'view', ...
$action = GETPOST('action', 'alpha') ? GETPOST('action', 'alpha') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$show_files = GETPOST('show_files', 'int');
$module = GETPOST('module', 'alpha');
// We click on a Cancel button
$cancel = GETPOST('cancel', 'alpha');
$toselect = GETPOST('toselect', 'array');                                                // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'googleapilist';   // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');                                            // Go back to a dedicated page
$optioncss = GETPOST('optioncss', 'aZ');                                                // Option for the css output (always '' except when 'print')
$pageToken = GETPOST('pageToken') ?: null;

$id = (int)GETPOST('id', 'int');
// for member
$rowid = (int)GETPOST('rowid', 'int');
$ref = GETPOST('ref', 'alpha');
// Note that conf->hooks_modules contains array
$hookmanager->initHooks(['googleapiemaillist']);


if ($action == 'linkemail' && GETPOST('confirm') === 'yes' && $toselect && $thirdpartyId = GETPOSTINT('societe_id')) {
	require_once __DIR__ . '/class/googleapi.class.php';
	$error = $success = 0;
	$db->begin();
	foreach ($toselect as $googleMailMessageRowid) {
		$googleMailMessage = GoogleApiGMailMessage::fetchInstance($googleMailMessageRowid);
		if ($googleMailMessage->rowid) {
			$googleMailMessage->object_type = 'societe';
			$googleMailMessage->object_id = $thirdpartyId;
			$success++;
			try {
				$googleMailMessage->save();
			} catch (Exception $e) {
				setEventMessage($e->getMessage(), 'errors');
			}
		}
	}

	if (!$error) {
		$db->commit();
		setEventMessage($langs->trans('GoogleApiLinkEmailSuccessful', $success));
		header("Location: {$_SERVER['PHP_SELF']}?pageToken={$pageToken}");
	} else {
		$db->rollback();
	}
}

// Protection if external user
$socid = 0;
if ($user->socid > 0) {
	//$socid = $user->socid;
	accessforbidden();
}
$arrayofjs = [
	'https://uicdn.toast.com/tui.code-snippet/latest/tui-code-snippet.js',
	'https://uicdn.toast.com/tui.pagination/latest/tui-pagination.js',
	'https://uicdn.toast.com/tui-grid/latest/tui-grid.js',
];
$arrayofcss = [
	'https://uicdn.toast.com/tui-grid/latest/tui-grid.css',
	'https://uicdn.toast.com/tui.pagination/latest/tui-pagination.css',
];

llxHeader('', 'Emails GMail', '', '', 0, 0, $arrayofjs, $arrayofcss);

$form = new Form($db);

$useTUIGrid = false;

if (!$useTUIGrid) {
	$resultPerPage = 50;
	// GoogleMail API use incremental page token, but no pagination. We save pages tokens to session to be able to add previous page button.
	$previousPageToken = null;
	$currentPageToken = $pageToken;
	$sessionKey = "general_pages_tokens";

	// If fitst page, we unset the session stored tokens
	if (!$currentPageToken) {
		unset($_SESSION[$sessionKey]);
	}
	$pagesTokens = $_SESSION[$sessionKey] ?? [];
	$data = [];
	$listLabel ='Vos emails Gmail';

	$gUser = 'me';
	$gMailFilterParts = [];

	$gMailFilter = implode(' OR ', $gMailFilterParts);

	try {
		$googleApiGmailMessages = getGoogleMailMessages($gMailFilterParts, $resultPerPage, $pageToken);
	} catch (Exception $e) {
		setEventMessage($e->getMessage(), 'errors');
		$googleApiGmailMessages = [];
	}

	// $pageToken is updated by reference with the next page token
	$nextPageToken = $pageToken;
	if ($nextPageToken) {
		$_SESSION[$sessionKey][] = $pageToken;
	}

	// Last page
	if ($currentPageToken && !$nextPageToken) {
		$currentPageKey = array_search($currentPageToken, $_SESSION[$sessionKey]);
		$previousPageToken = $_SESSION[$sessionKey][$currentPageKey-1] ?? null;
	}

	ob_start()
	?>
		<div class="pagination">
			<ul>
				<?php if ($previousPageToken || $currentPageToken) : ?>
				<li class="pagination paginationpage paginationpageleft">
					<a class="paginationprevious reposition" href="<?= $_SERVER['PHP_SELF'] ?>?pageToken=<?= $previousPageToken ?>">
						<i class="fa fa-chevron-left" title="Précédent"></i>
					</a>
				</li>
				<?php else : ?>
					<li class="pagination paginationpage paginationpageleft">
						<i class="fa fa-chevron-left" title="Précédent" style="opacity: 0.4"></i>
					</li>
				<?php endif; ?>
				<?php if ($nextPageToken) : ?>
					<li class="pagination paginationpage paginationpageright">
						<a class="paginationnext reposition" href="<?= $_SERVER['PHP_SELF'] ?>?pageToken=<?= $nextPageToken ?>">
							<i class="fa fa-chevron-right" title="Suivant"></i></a>
					</li>
				<?php else : ?>
					<li class="pagination paginationpage paginationpageright">
						<i class="fa fa-chevron-right" title="Précédent" style="opacity: 0.4"></i>
					</li>
				<?php endif; ?>
			</ul>
		</div>
	<?php
	$moreHtml = ob_get_clean();
	$arrayofmassactions = [];
	$arrayofmassactions['prelinkemail'] = '<span class="fa fa-link paddingrightonly"></span>'.$langs->trans("GoogleApiPreLinkEmail");

	$massactionbutton = $form->selectMassAction($massaction, $arrayofmassactions);

	?>
	<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
		<input type="hidden" name="pageToken" value="<?= $currentPageToken ?>">
	<?php
		print_barre_liste($listLabel, 0, $_SERVER["PHP_SELF"], '', '', '', $massactionbutton, 0, 0, '', '', $moreHtml);
	if ($massaction == 'prelinkemail') {
		$questions = [
			['type' => 'other', 'name' => 'societe_id', 'label' => $langs->trans('ThirdParty'), 'value' => $form->select_company('', 'societe_id', '', 1)]
		];
		print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans("GoogleApiLinkEmail"), $langs->trans("GoogleApiConfirmLinkEMail"), "linkemail", $questions, '', 0, 200, 500, 1);
	}
	?>
		<div class="div-table-responsive-no-min">
			<table class="noborder centpercent nomarginbottom">
				<tbody>
				<tr class="liste_titre">
					<td></td>
					<td>Date</td>
					<td>De</td>
					<td>A</td>
					<td>Sujet</td>
					<td>Body</td>
					<td>Objet référent</td>
					<td><div class="inline-block checkallactions"><input type="checkbox" id="checkforselects" name="checkforselects" class="checkallactions"></div></td>
				</tr>
				<script>
					$(document).ready(function() {
						$("#checkforselects").click(function() {
							if($(this).is(':checked')){
								$(".checkforselect").prop('checked', true).trigger('change');
							}
							else {
								$(".checkforselect").prop('checked', false).trigger('change');
							}
							if (typeof initCheckForSelect == 'function') { initCheckForSelect(0, "massaction", "checkforselect"); } else { console.log("No function initCheckForSelect found. Call won't be done."); }         });
						$(".checkforselect").change(function() {
							$(this).closest("tr").toggleClass("highlight", this.checked);
						});
					});
				</script>

				<?php if (!empty($googleApiGmailMessages)) : ?>
					<?php foreach ($googleApiGmailMessages as $googleApiGmailMessage) : ?>
						<?php
							$backgroundColor = $googleApiGmailMessage->outgoing ? 'lightblue' : 'lightgreen';

							$sourceObject = null;
							$elementProperties = getElementProperties($googleApiGmailMessage->object_type);
							if (!empty($elementProperties['classname']) && !empty($elementProperties['classpath']) && !empty($elementProperties['classfile'])) {
								require_once DOL_DOCUMENT_ROOT . '/' . $elementProperties['classpath'] . '/' . $elementProperties['classfile'] . '.class.php';
								$sourceObject = new $elementProperties['classname']($db);
								$sourceObject->fetch($googleApiGmailMessage->object_id);
							}
							switch ($googleApiGmailMessage->object_type) {
								case 'societe':

							}
						?>
						<tr data-messageid="<?= $googleApiGmailMessage->message_id ?>"
							data-subject="<?= $googleApiGmailMessage->subject ?>"
							style="cursor: pointer; background: <?= $backgroundColor ?>!important;<?php if ($googleApiGmailMessage->unread) : ?>font-weight: bold; <?php endif; ?>">
							<td class="tdoverflowmax200 col_date">
								<?php if ($googleApiGmailMessage->outgoing) : ?>
									<i class="fa fa-upload" style="color: midnightblue"></i>
								<?php else: ?>
									<i class="fa fa-download" style="color: darkgreen"></i>
								<?php endif; ?>
							</td>
							<td class="tdoverflowmax200 col_date googlemailmessage-show-details">
								<?= dol_print_date($googleApiGmailMessage->date, 'dayhour') ?>
							</td>
							<td class="tdoverflowmax200 col_from googlemailmessage-show-details"><?= htmlentities($googleApiGmailMessage->email_from) ?></td>
							<td class="tdoverflowmax200 col_to googlemailmessage-show-details"><?= htmlentities($googleApiGmailMessage->email_to) ?></td>
							<td class="tdoverflowmax200 col_subject googlemailmessage-show-details"><?= $googleApiGmailMessage->subject ?></td>
							<td class="tdoverflowmax500 col_body googlemailmessage-show-details">
								<span class="classfortooltip"
									  title="<?= $googleApiGmailMessage->snippet ?>"><?= $googleApiGmailMessage->snippet ?></span>
							</td>
							<td><?= !empty($sourceObject) && is_object($sourceObject) && method_exists($sourceObject, 'getNomUrl') ? $sourceObject->getNomUrl(1) : '' ?></td>
							<td>
								<input id="gm<?= $googleApiGmailMessage->rowid ?>" class="flat checkforselect" type="checkbox" name="toselect[]" value="<?= $googleApiGmailMessage->rowid ?>" <?php if (in_array($googleApiGmailMessage->rowid, $toselect)) : ?>checked <?php endif; ?>>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</form>
	<?php
} else {
	//TODO Remove when API data will be stored in db
	unset($_SESSION["googleapi_page_token_{$object->element}_{$object->id}"]);
	?>
	<div id="grid"></div>
	<script>
		const grid = new tui.Grid({
			usageStatistics: false,
			el: document.getElementById('grid'),
			data: {
				api: {
					readData: {
						url: '<?= dol_buildpath('googleapi/core/ajax/check_object_emails.php', 1) ?>?object_type=<?= $object->element ?>&object_id=<?= $object->id ?>',
						method: 'GET'
					},
				}
			},
			scrollX: false,
			scrollY: false,
			minBodyHeight: 35,
			rowHeaders: ['rowNum'],
			pageOptions: {
				perPage: 25
			},
			columns: [
				{
					header: '<?= $langs->trans('Date') ?>',
					name: 'date',
					width: 150
				},
				{
					header: '<?= $langs->trans('GoogleApiFrom') ?>',
					name: 'from',
					width: 150
				},
				{
					header: '<?= $langs->transnoentities('GoogleApiTo') ?>',
					name: 'to',
					width: 150
				},
				{
					header: '<?= $langs->trans('GoogleApiSubject') ?>',
					name: 'subject',
					width: 250
				},
				{
					header: '<?= $langs->trans('GoogleApiBody') ?>',
					name: 'body_snippet',
				},
			],
			columnOptions: {
				resizable: true
			}
		});
		grid.on('click', ev => {
			console.log('click!', ev);
			//grid.request('modifyData');
		});
		grid.on('columnResize', ev => {
			console.log('columnResize!', ev);
		});
	</script>

	<?php
}
	//	$arrayfields = array(
//		'rowid' => array(
//			'label' => $langs->transnoentities("Id"),
//			'checked' => 1
//		),
//		'userid' => array(
//			'label' => $langs->transnoentities("GoogleApiUserId"),
//			'checked' => 1
//		),
//		'fk_object' => array(
//			'label' => $langs->transnoentities("ObjectId"),
//			'checked' => 1,
//		),
//		'messageid' => array(
//			'label' => $langs->transnoentities("GoogleApiMessageId"),
//			'checked' => 1,
//		),
//	);
//
//	print '<div id="grid"></div>';
//	print "<script>
//		const grid = new tui.Grid({
//			usageStatistics: false,
//			el: document.getElementById('grid'),
//			data: {
//				api: {
//					readData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=getemails&id=" . $id . "&module=" . $module . "', method: 'GET' },
//					// only modified data
//					updateData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=putemails&id=" . $id . "&module=" . $module . "', method: 'PUT' },
//					// all modified
//					modifyData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=putemails&id=" . $id . "&module=" . $module . "', method: 'PUT' }
//				}
//			},
//			scrollX: false,
//			scrollY: false,
//			minBodyHeight: 35,
//			rowHeaders: ['rowNum'],
//			pageOptions: {
//				perPage: 25
//			},
//			columns: [
//				{
//					header: '" . $arrayfields['rowid']['label'] . "',
//					name: 'rowid',
//					width: 0
//				},
//				{
//					header: '" . $arrayfields['userid']['label'] . "',
//					name: 'userid',
//					width: 0
//				},
//				{
//					header: '" . $arrayfields['fk_object']['label'] . "',
//					name: 'fk_object',
//					width: 0
//				},
//				{
//					header: '" . $arrayfields['messageid']['label'] . "',
//					name: 'messageid'
//				}
//			],
//			columnOptions: {
//				resizable: true
//			}
//		});
//		grid.on('click', ev => {
//			console.log('click!', ev);
//			//grid.request('modifyData');
//		});
//		grid.on('columnResize', ev => {
//			console.log('columnResize!', ev);
//		});
//	</script>";
// End of page
llxFooter();
$db->close();
