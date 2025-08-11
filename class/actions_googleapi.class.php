<?php
/* Copyright (C) 2019-2021  Frédéric France         <frederic.france@netlogic.fr>
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
 * \file    htdocs/googleapi/class/actions_googleapi.class.php
 * \ingroup googleapi
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

dol_include_once('/prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');
dol_include_once('/googleapi/lib/googleapi.lib.php');


/**
 * Class ActionsGoogleApi
 */
class ActionsGoogleApi
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 *  @var array Errors
	 */
	public $errors = [];


	/**
	 *  @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = [];

	/**
	 *  @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 *  Constructor
	 *
	 *  @param  DoliDB  $db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Execute action
	 *
	 * @param   array           $parameters     Array of parameters
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         'add', 'update', 'view'
	 * @return  int                             <0 if KO,
	 *                                          =0 if OK but we want to process standard actions too,
	 *                                          >0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overloading the moveUploadedFile function.
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function moveUploadedFile($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		// hook fileslib
		// add or rename ==> moveUploadedFile
		// delete ==> deleteFile
		if (!empty($conf->global->GOOGLEAPI_ENABLE_DEVELOPPER_MODE)) {
			setEventMessage('hook fichier googleapi déplacé dans ' . $object->element);
		}
		return 0;
	}

	/**
	 * Overloading the deleteFile function.
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function deleteFile($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		//setEventMessage('hook fichier googleapi effacé depuis ' . $object->element);
		return 0;
	}

	/**
	 * Execute action completeTabsHead
	 *
	 * @param   array           $parameters     Array of parameters
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         'add', 'update', 'view'
	 * @param   Hookmanager     $hookmanager    hookmanager
	 * @return  int                             <0 if KO,
	 *                                          =0 if OK but we want to process standard actions too,
	 *                                          >0 if OK and we want to replace standard actions.
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf, $user;

		// var_dump($parameters['object']);
		// var_dump($parameters['mode']);
		// var_dump($parameters);
		if (!isset($parameters['object']->element)) {
			return 0;
		}
		if ($parameters['mode'] == 'remove') {
			// utilisé si on veut faire disparaitre des onglets.
			return 0;
		} elseif ($parameters['mode'] == 'add') {
			if (isset($parameters['filterorigmodule']) && $parameters['filterorigmodule'] == 'core') {
				// avoid to have two tabs when filter is active
				return 0;
			}
			$langs->load('googleapi@googleapi');
			// utilisé si on veut ajouter des onglets.
			$counter = count($parameters['head']);
			$element = $parameters['object']->element;
			$id = $parameters['object']->id;
			// verifier le type d'onglet comme member_stats où ça ne doit pas apparaitre
			if (in_array($element, ['societe', 'member', 'contrat', 'fichinter', 'project', 'propal', 'commande', 'facture', 'order_supplier', 'invoice_supplier'])) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/memory.lib.php';
				$emailcount = 0;
				$cachekey = 'count_gapiemails_' . $element . '_' . $id;
				$dataretrieved = dol_getcache($cachekey);
				if (!is_null($dataretrieved)) {
					$emailcount = $dataretrieved;
				} else {
					$sql = 'SELECT count(rowid) as nb FROM ' . MAIN_DB_PREFIX . 'googleapi_emails_sent';
					$sql .= ' WHERE userid=' . (int) $user->id . ' AND module="' . $element . '"';
					$sql .= ' AND fk_object=' . (int) $id;
					$resql = $this->db->query($sql);
					if ($resql && $obj = $this->db->fetch_object($resql)) {
						$emailcount = $obj->nb;
					}
					// If setting cache fails, this is not a problem, so we do not test result.
					dol_setcache($cachekey, $emailcount, 120);
				}
				$parameters['head'][$counter][0] = dol_buildpath('/googleapi/tabs/googleapi_emails.php', 1) . '?id=' . $id . '&amp;module=' . $element;
				$parameters['head'][$counter][1] = img_picto($langs->trans('GoogleApiMailsTab'), 'object_googleapi@googleapi'); //.$langs->trans('GoogleApiMailsTab');
				if ($emailcount > 0) {
					$parameters['head'][$counter][1] .= '<span class="badge marginleftonlyshort">' . $emailcount . '</span>';
				}
				$parameters['head'][$counter][2] = 'googleapiemails';
				$counter++;
			}
			if (in_array($element, ['user'])) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/memory.lib.php';
				$parameters['head'][$counter][0] = dol_buildpath('/googleapi/tabs/usertoken.php', 1) . '?id=' . $id;
				$parameters['head'][$counter][1] = img_picto($langs->trans('GoogleApiTokenTab'), 'object_googleapi@googleapi');
				$parameters['head'][$counter][2] = 'googleapitoken';
				$counter++;
			}
			if ($counter > 0 && (int) DOL_VERSION < 14) {
				$this->results = $parameters['head'];
				// return 1 to replace standard code
				return 1;
			} else {
				// en V14 et + $parameters['head'] est modifiable par référence
				return 0;
			}
			return 0;
		}
	}

	/**
	 * Overloading the sendMail function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CmailFile    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function sendMail($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $googleapiMessageId;

		$error = 0; // Error counter

		// print '<pre>'.print_r($parameters, true).'</pre>';
		// print '<pre>'.print_r($object, true).'</pre>';
		// echo "action: " . $action;exit;
		// what TODO with context 'emailing'
		// context notification?
		// https://developers.google.com/resources/api-libraries/documentation/gmail/v1/php/latest/class-Google_Service_Gmail_Message.html
		if (in_array($parameters['currentcontext'], array('mail')) && !in_array($object->sendcontext, array('emailing', 'notification'))) {
			dol_include_once('/googleapi/lib/googleapi.lib.php');
			$fromsender = $this->getArrayAddress($object->addr_from);
			if (!empty($user->array_options['options_googleapi_email']) && $fromsender[0]['address'] == $user->array_options['options_googleapi_email']) {
				$client = getGoogleApiClient($user);
				$service = new Google\Service\Gmail($client);

				$replytosender = $this->getArrayAddress($object->reply_to);
				$addrtorecipients = $this->getArrayAddress($object->addr_to);
				$addrccrecipients = $this->getArrayAddress($object->addr_cc);
				$addrbccrecipients = $this->getArrayAddress($object->addr_bcc);

				$message = new Google\Service\Gmail\Message();
				$mime = rtrim(strtr(base64_encode($object->message), '+/', '-_'), '=');
				$message->setRaw($mime);

				$mailsent = false;
				try {
					$response = $service->users_messages->send('me', $message);
					$mailsent = true;
				} catch (Exception $e) {
					$this->errors[] = $e->getMessage();
					setEventMessage($e->getMessage(), 'errors');
					$error++;
				}
				if ($mailsent) {
					$googleapiMessageId = $response->getId();
				}
				//var_dump($response);exit;
			} else {
				// nothing done
				return 0;
			}
		}

		if (!$error) {
			//$this->results = array('msgid' => 'azerty');
			//$this->resprints = 'A text to show';
			// 1 si on a envoyé avec googleapi sinon 0
			return 1; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Overloading the sendMailAfter function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function sendMailAfter($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter

		// print '<pre>'.print_r($parameters, true).'</pre>';
		// print '<pre>'.print_r($object, true).'</pre>';
		// print "action: " . $action;exit;
		return 0;
	}

	/**
	 * Edits the login form to allow entering MicosoftGraph Login
	 * @return void
	 */
	public function getLoginPageOptions()
	{
		global $langs, $conf;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

		$langs->load('googleapi@googleapi');
		$urllogin = 'https://login.google.com/common/oauth2/v2.0/authorize?'; // TODO fix URL
		$urllogin .= 'client_id=' . urlencode(getDolGlobalString('OAUTH_GOOGLEAPI_ID'));
		// id_token sous forme jwt à décoder et valider
		$urllogin .= '&amp;response_type=id_token';
		// TODO check http https
		$urllogin .= '&amp;redirect_uri=' . urlencode(str_replace('http://', 'https://', dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 2)));
		$urllogin .= '&amp;response_mode=form_post';
		$urllogin .= '&amp;scope=openid';
		// utiliser state pour dire checklogin
		$urllogin .= '&amp;state=checklogin';
		// générer "nonce" et stocker dans la session pour le vérifier au retour
		$nonce = random_int(100000, 999999);
		$_SESSION['nonce'] = $nonce;
		$urllogin .= '&amp;nonce=' . $nonce;

		$tpl = '<div class="nowrap center valignmiddle">';
		$tpl .= '<a class="butAction" href="' . $urllogin . '">';
		$tpl .= '<img src="' . dol_buildpath('/googleapi/img/google.png', 1) . '" style="width:140px;height:25px;border:0;">';
		$tpl .= '</a>';
		$tpl .= '</div>' . "\n";

		$this->resprints = $tpl;
		return 0;
	}

	/**
	 * Add an icon in the top right menu
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf, $form, $user;

		$langs->load('googleapi@googleapi');
		// require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
		if (!is_object($form)) {
			$form = new Form($this->db);
		}
		$sql = 'SELECT unread FROM ' . MAIN_DB_PREFIX . 'googleapi_mailboxes WHERE userid=' . (int) $user->id;
		$resql = $this->db->query($sql);
		$unread = 0;
		if ($resql && $obj = $this->db->fetch_object($resql)) {
			$unread = (int) $obj->unread;
		}
		$langs->load('googleapi@googleapi');

		// CSS for Badge Count
		$cssforbadge = '
		<style type="text/css">
			.fa-stack[data-count]:after{
				position:absolute;
				right:0%;
				top:1%;
				content: attr(data-count);
				font-size:35%;
				padding:.6em;
				border-radius:999px;
				line-height:.75em;
				color: white;
				background:rgba(255,0,0,.85);
				text-align:center;
				min-width:2em;
				font-weight:bold;
			}
		</style>';

		$text = $cssforbadge;
		$text = '<a href="https://gmail.google.com" target="_blank">';
		//$text.= img_picto(":".$langs->trans("GoogleApiEmailInbox"), 'printer_top.png', 'class="printer"');
		$text .= '<span id="googleapicounter" class="fa-stack fa-2x has-badge atoplogin login_block_elem" data-count="' . $unread . '">';
		$text .= '    <i class="fa fa-envelope fa-stack-1x atoplogin login_block_elem"></i>';
		//$text .= '    <i class="fa fa-bell fa-stack-1x fa-inverse atoplogin login_block_elem"></i>';
		$text .= '</span>';
		$text .= '</a>';
		$info = $langs->trans("GoogleApiUnreadEmailNone");
		if ($unread == 1) {
			$info = $langs->trans("GoogleApiUnreadEmailOne");
		} elseif ($unread > 1) {
			$info = $langs->trans("GoogleApiUnreadEmailMany", $unread);
		}
		$tpl = $form->textwithtooltip('', $info, 2, 1, $text, 'googleapicounterinfo login_block_elem', 2);

		$this->resprints = $tpl;
		return 0;
	}

	/**
	 * Return a formatted array of address string for SMTP protocol
	 *
	 * @param   string  $address    Example: 'John Doe <john@doe.com>, Alan Smith <alan@smith.com>'
	 *                              or 'john@doe.com, alan@smith.com'
	 * @return  array               array of email => name
	 */
	private function getArrayAddress($address)
	{
		global $conf;

		$ret = [];
		if (!empty($address)) {
			$arrayaddress = explode(',', $address);
			// Boucle sur chaque composant de l'adresse
			foreach ($arrayaddress as $val) {
				if (preg_match('/^(.*)<(.*)>$/i', trim($val), $regs)) {
					$name  = trim($regs[1]);
					$email = trim($regs[2]);
				} else {
					$name  = null;
					$email = trim($val);
				}
				$ret[] = array(
					'name' => empty($conf->global->MAIN_MAIL_NO_FULL_EMAIL) ? $name : null,
					'address' => $email,
				);
			}
		}
		return $ret;
	}
}
