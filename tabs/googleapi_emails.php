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
$toselect   = GETPOST('toselect', 'array');                                                // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'googleapilist';   // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');                                            // Go back to a dedicated page
$optioncss  = GETPOST('optioncss', 'aZ');                                                // Option for the css output (always '' except when 'print')

$id = (int) GETPOST('id', 'int');
// for member
$rowid = (int) GETPOST('rowid', 'int');
$ref = GETPOST('ref', 'alpha');
// Note that conf->hooks_modules contains array
$hookmanager->initHooks(array('googleapiemaillist', 'globalcard'));

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
$arrayofjs = array(
	'https://uicdn.toast.com/tui.code-snippet/latest/tui-code-snippet.js',
	'https://uicdn.toast.com/tui.pagination/latest/tui-pagination.js',
	'https://uicdn.toast.com/tui-grid/latest/tui-grid.js',
);
$arrayofcss = array(
	'https://uicdn.toast.com/tui-grid/latest/tui-grid.css',
	'https://uicdn.toast.com/tui.pagination/latest/tui-pagination.css',
);

llxHeader('', $pagetitle, '', '', 0, 0, $arrayofjs, $arrayofcss);

$form = new Form($db);

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

	$arrayfields = array(
		'rowid' => array(
			'label' => $langs->transnoentities("Id"),
			'checked' => 1
		),
		'userid' => array(
			'label' => $langs->transnoentities("GoogleApiUserId"),
			'checked' => 1
		),
		'fk_object' => array(
			'label' => $langs->transnoentities("ObjectId"),
			'checked' => 1,
		),
		'messageid' => array(
			'label' => $langs->transnoentities("GoogleApiMessageId"),
			'checked' => 1,
		),
	);

	print '<div id="grid"></div>';
	print "<script>
		const grid = new tui.Grid({
			usageStatistics: false,
			el: document.getElementById('grid'),
			data: {
				api: {
					readData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=getemails&id=" . $id . "&module=" . $module . "', method: 'GET' },
					// only modified data
					updateData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=putemails&id=" . $id . "&module=" . $module . "', method: 'PUT' },
					// all modified
					modifyData: { url: '" . dol_buildpath('/googleapi/core/ajax/check_emails_sent.php', 1) . "?action=putemails&id=" . $id . "&module=" . $module . "', method: 'PUT' }
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
					header: '" . $arrayfields['rowid']['label'] . "',
					name: 'rowid',
					width: 100
				},
				{
					header: '" . $arrayfields['userid']['label'] . "',
					name: 'userid',
					width: 100
				},
				{
					header: '" . $arrayfields['fk_object']['label'] . "',
					name: 'fk_object',
					width: 100
				},
				{
					header: '" . $arrayfields['messageid']['label'] . "',
					name: 'messageid'
				}
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
	</script>";
}
// End of page
llxFooter();
$db->close();
