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
include '../config.php';

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
//dol_include_once('/googleapi/class/googleapi.class.php');

// Load translation files required by the page
$langs->load("googleapi@googleapi");

// The action 'add', 'create', 'edit', 'update', 'view', ...
$action = GETPOST('action', 'alpha') ? GETPOST('action', 'alpha') : 'view';
$show_files = GETPOST('show_files', 'int');
$module = GETPOST('module', 'alpha');
// We click on a Cancel button
$cancel = GETPOST('cancel', 'alpha');
$toselect = GETPOST('toselect', 'array');                                                // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'googleapilist';   // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');                                            // Go back to a dedicated page
$optioncss = GETPOST('optioncss', 'aZ');                                                // Option for the css output (always '' except when 'print')

$id = (int) GETPOST('id', 'int');
// for member
$rowid = (int) GETPOST('rowid', 'int');
$ref = GETPOST('ref', 'alpha');
// Note that conf->hooks_modules contains array
$hookmanager->initHooks(['googleapiemaillist', 'globalcard']);

$linkback = '';
// Initialize technical objects
if ($module == 'societe') {
	$langs->load('companies');
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
	$object = new Societe($db);
	if ($id > 0 || !empty($ref)) {
		if ($object->fetch($id, $ref) > 0) {
			$id = $object->id;
			$object->fetch_thirdparty();
		}
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
	$head = societe_prepare_head($object);
	$title = $langs->trans("Customer");
	$pagetitle = $langs->trans('Customer');
	$picto = 'company';
	$linkback = '<a href="' . DOL_URL_ROOT . '/societe/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'member') {
	$langs->load('members');
	require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
	$object = new Adherent($db);
	if ($id > 0 || !empty($ref)) {
		if ($object->fetch($id, $ref) > 0) {
			$id = $object->id;
			$rowid = $object->id;
			$object->fetch_thirdparty();
		} else {
			dol_print_error($db);
		}
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/member.lib.php';
	$head = member_prepare_head($object);
	$title = $langs->trans("Member");
	$pagetitle = $langs->trans('Member');
	$picto = 'member';
	$linkback = '<a href="' . DOL_URL_ROOT . '/adherents/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'contrat') {
	$langs->load('contracts');
	require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';
	$object = new Contrat($db);
	if ($id > 0 || !empty($ref)) {
		if ($object->fetch($id, $ref) > 0) {
			$id = $object->id;
			$object->fetch_thirdparty();
		}
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/contract.lib.php';
	$head = contract_prepare_head($object);
	$title = $langs->trans("Contract");
	$pagetitle = $langs->trans("Contract");
	$picto = 'contract';
	$linkback = '<a href="' . DOL_URL_ROOT . '/contrat/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'commande') {
	$langs->load('orders');
	require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
	$object = new Commande($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/order.lib.php';
	$head = commande_prepare_head($object);
	$title = $langs->trans("CustomerOrder");
	$pagetitle = $langs->trans('Order');
	$picto = 'order';
	$linkback = '<a href="' . DOL_URL_ROOT . '/commande/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'order_supplier') {
	$langs->load('orders');
	$langs->load('companies');
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
	$object = new CommandeFournisseur($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/fourn.lib.php';
	$head = ordersupplier_prepare_head($object);
	$title = $langs->trans("SupplierOrder");
	$pagetitle = $langs->trans('SupplierOrder');
	$picto = 'order';
	$linkback = '<a href="' . DOL_URL_ROOT . '/fourn/commande/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'propal') {
	$langs->load('propal');
	require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
	$object = new Propal($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/propal.lib.php';
	$head = propal_prepare_head($object);
	$title = $langs->trans('Proposal');
	$pagetitle = $langs->trans('Proposal');
	$picto = 'propal';
	$linkback = '<a href="' . DOL_URL_ROOT . '/comm/propal/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'fichinter') {
	$langs->load('interventions');
	require_once DOL_DOCUMENT_ROOT . '/fichinter/class/fichinter.class.php';
	$object = new Fichinter($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/fichinter.lib.php';
	$head = fichinter_prepare_head($object);
	$title = $langs->trans('Intervention');
	$pagetitle = $langs->trans('Intervention');
	$picto = 'intervention';
	$linkback = '<a href="' . DOL_URL_ROOT . '/fichinter/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'facture') {
	$langs->load('bills');
	$langs->load('banks');
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
	$object = new Facture($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
	$head = facture_prepare_head($object);
	$title = $langs->trans('Invoice');
	$pagetitle = $langs->trans('Invoice');
	$picto = 'invoice';
	$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} elseif ($module == 'project') {
	$langs->load('projects');
	require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
	$object = new Project($db);
	if (($id > 0 || !empty($ref)) && ($object->fetch($id, $ref) > 0)) {
		$id = $object->id;
		$object->fetch_thirdparty();
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/project.lib.php';
	$head = project_prepare_head($object);
	$title = $langs->trans('Project');
	$pagetitle = $langs->trans('Project');
	$picto = 'project';
	$linkback = '<a href="' . DOL_URL_ROOT . '/projet/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';
} else {
	print $module;
	exit;
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

llxHeader('', $pagetitle, '', '', 0, 0, $arrayofjs, $arrayofcss);

$form = new Form($db);

$useTUIGrid = false;
if ($id > 0 || !empty($ref)) {
	print dol_get_fiche_head($head, 'googleapiemails', $title, -1, $picto);

	// Card
	$morehtmlref = '<div class="refidno">';
	if (in_array($module, ['commande', 'order_supplier', 'project', 'propal'])) {
		// Ref customer
		$morehtmlref .= $form->editfieldkey("RefCustomer", 'ref_client', $object->ref_client, $object, 0, 'string', '', 0, 1);
		$morehtmlref .= $form->editfieldval("RefCustomer", 'ref_client', $object->ref_client, $object, 0, 'string', '', null, null, '', 1);
	}
	// Thirdparty
	if (is_object($object->thirdparty)) {
		$morehtmlref .= '<br>' . $langs->trans('ThirdParty') . ' : ' . $object->thirdparty->getNomUrl(1);
	}
	$morehtmlref .= '</div>';

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '&amp;module=' . $module, 0, '', '', 1);

	print dol_get_fiche_end();

	print '<br>';

	print '<br>';


	if (!$useTUIGrid) {
		$resultPerPage = 50;
		// GoogleMail API use incremental page token, but no pagination. We save pages tokens to session to be able to add previous page button.
		$previousPageToken = null;
		$pageToken = $currentPageToken = GETPOST('pageToken') ?: null;
		$sessionKey = "{$object->element}_{$object->id}_pages_tokens";

		// If fitst page, we unset the session stored tokens
		if (!$currentPageToken) {
			unset($_SESSION[$sessionKey]);
		}
		$pagesTokens = $_SESSION[$sessionKey] ?? [];
		$data = [];
		if (get_class($object) === "Societe" && $object->email) {
			$showContactsMessages = true;
			$listLabel = $showContactsMessages ? 'Communications du tiers et de ses contacts' : 'Communications du tiers';

			$gUser = 'me';
			$gMailFilterParts = ["(to:{$object->email} OR from:{$object->email})"];

			if ($showContactsMessages) {
				$contactsEmails = array_column($object->contact_array_objects(), 'email');
				foreach ($contactsEmails as $contactEmail) {
					$gMailFilterParts[] = "(to:{$contactEmail} OR from:{$contactEmail})";
				}
			}

			$gMailFilter = implode(' OR ', $gMailFilterParts);

			try {
				$googleApiGmailMessages = getGoogleMailMessages($gMailFilterParts, $resultPerPage, $pageToken, null, $object->element, $object->id);
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
				$previousPageToken = $_SESSION[$sessionKey][$currentPageKey - 1] ?? null;
			}
			ob_start()
			?>
			<div class="pagination">
				<ul>
					<?php if ($previousPageToken || $currentPageToken) : ?>
						<li class="pagination paginationpage paginationpageleft">
							<a class="paginationprevious reposition" href="<?= $_SERVER['PHP_SELF'] ?>?id=<?= $object->id ?>&module=<?= $object->element ?>&pageToken=<?= $previousPageToken ?>">
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
							<a class="paginationnext reposition" href="<?= $_SERVER['PHP_SELF'] ?>?id=<?= $object->id ?>&module=<?= $object->element ?>&pageToken=<?= $nextPageToken ?>">
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
			print_barre_liste("Communications Tiers et Contacts", 0, $_SERVER["PHP_SELF"], '', '', '', '', 0, 0, '', '', $moreHtml);
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
					</tr>
					<?php if (!empty($googleApiGmailMessages)) : ?>
						<?php foreach ($googleApiGmailMessages as $googleApiGmailMessage) : ?>
							<?php
							$backgroundColor = $googleApiGmailMessage->outgoing ? 'lightblue' : 'lightgreen';
							?>
							<tr class="googlemailmessage-show-details"
								data-messageid="<?= $googleApiGmailMessage->message_id ?>"
								data-subject="<?= $googleApiGmailMessage->subject ?>"
								style="cursor: pointer; background: <?= $backgroundColor ?>!important;">
								<td class="tdoverflowmax200 col_date">
									<?php if ($googleApiGmailMessage->outgoing) : ?>
										<i class="fa fa-upload" style="color: midnightblue"></i>
									<?php else: ?>
										<i class="fa fa-download" style="color: darkgreen"></i>
									<?php endif; ?>
								</td>
								<td class="tdoverflowmax200 col_date">
									<?= dol_print_date($googleApiGmailMessage->date, 'dayhour') ?>
								</td>
								<td class="tdoverflowmax200 col_from"><?= htmlentities($googleApiGmailMessage->email_from) ?></td>
								<td class="tdoverflowmax200 col_to"><?= htmlentities($googleApiGmailMessage->email_to) ?></td>
								<td class="tdoverflowmax200 col_subject"><?= $googleApiGmailMessage->subject ?></td>
								<td class="tdoverflowmax500 col_body">
									<span class="classfortooltip"
										title="<?= $googleApiGmailMessage->snippet ?>"><?= $googleApiGmailMessage->snippet ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

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
				columns: [{
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
}
// End of page
llxFooter();
$db->close();
