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
 * \file    core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php
 * \ingroup googleapi
 * \brief   GoogleApi trigger.
 *
 * Put detailed description here.
 *
 * \remarks You can create other triggers by copying this one.
 * - File name should be either:
 *      - interface_99_modGoogleApi_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMytrigger
 * - The constructor method must be named InterfaceMytrigger
 * - The name property name must be MyTrigger
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
dol_include_once('/googleapi/lib/googleapi.lib.php');
dol_include_once('/prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Grant\RefreshToken;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;


/**
 *  Class of triggers for GoogleApi module
 */
class InterfaceGoogleApiTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "Net-Logic";
		$this->description = "GoogleApi triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'dolibarr';
		$this->picto = 'googleapi@googleapi';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string        $action     Event action code
	 * @param CommonObject  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (empty($conf->googleapi->enabled)) {
			// Module not active, we do nothing
			return 0;
		}

		if (get_class($object) == 'ActionComm' && empty($conf->global->GOOGLEAPI_INCLUDE_AUTO_EVENT) && $object->type_code == 'AC_OTH_AUTO') {
			// we don't want to pollute calendar with auto events
			return 0;
		}
		if (get_class($object) == 'ActionComm' && !empty($object->context['googleapi'])) {
			// object comes from api googleapi
			return 0;
		}
		if (get_class($object) == 'Contact' && !empty($object->context['googleapi'])) {
			// object comes from api googleapi
			return 0;
		}

		// You can isolate code for each action in a separate method: this method should be named like the trigger in camelCase.
		// For example : COMPANY_CREATE => public function companyCreate($action, $object, User $user, Translate $langs, Conf $conf)
		$methodName = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		$callback = array($this, $methodName);
		if (is_callable($callback)) {
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id, LOG_INFO);
			return call_user_func($callback, $action, $object, $user, $langs, $conf);
		};

		// Users
		// USER_CREATE
		// USER_MODIFY
		// USER_NEW_PASSWORD
		// USER_ENABLEDISABLE
		// USER_DELETE
		// USER_SETINGROUP
		// USER_REMOVEFROMGROUP

		// Groups
		// GROUP_CREATE
		// GROUP_MODIFY
		// GROUP_DELETE

		// Companies
		// COMPANY_CREATE
		// COMPANY_MODIFY
		// COMPANY_DELETE

		// Actions
		// ACTION_CREATE
		// ACTION_MODIFY
		// ACTION_DELETE

		// Holidays
		// HOLIDAY_CREATE
		// HOLIDAY_VALIDATE
		// HOLIDAY_MODIFY
		// HOLIDAY_APPROVE
		// HOLIDAY_DELETE

		// Contacts
		// CONTACT_CREATE
		// CONTACT_MODIFY
		// CONTACT_DELETE
		// CONTACT_ENABLEDISABLE
		// CONTACT_SENTBYMAIL
		// COMPANY_SENTBYMAIL

		// Products
		// PRODUCT_CREATE
		// PRODUCT_MODIFY
		// PRODUCT_DELETE
		// PRODUCT_PRICE_MODIFY
		// PRODUCT_SET_MULTILANGS
		// PRODUCT_DEL_MULTILANGS

		// Stock mouvement
		// STOCK_MOVEMENT

		// MYECMDIR
		// MYECMDIR_CREATE
		// MYECMDIR_MODIFY
		// MYECMDIR_DELETE

		// Customer orders
		// ORDER_CREATE
		// ORDER_MODIFY
		// ORDER_VALIDATE
		// ORDER_DELETE
		// ORDER_CANCEL
		// ORDER_SENTBYMAIL
		// ORDER_CLASSIFY_BILLED
		// ORDER_SETDRAFT
		// LINEORDER_INSERT
		// LINEORDER_UPDATE
		// LINEORDER_DELETE

		// Supplier orders
		// ORDER_SUPPLIER_CREATE
		// ORDER_SUPPLIER_MODIFY
		// ORDER_SUPPLIER_VALIDATE
		// ORDER_SUPPLIER_DELETE
		// ORDER_SUPPLIER_APPROVE
		// ORDER_SUPPLIER_REFUSE
		// ORDER_SUPPLIER_CANCEL
		// ORDER_SUPPLIER_SENTBYMAIL
		// ORDER_SUPPLIER_DISPATCH
		// LINEORDER_SUPPLIER_DISPATCH
		// LINEORDER_SUPPLIER_CREATE
		// LINEORDER_SUPPLIER_UPDATE
		// LINEORDER_SUPPLIER_DELETE

		// Proposals
		// PROPAL_CREATE
		// PROPAL_MODIFY
		// PROPAL_VALIDATE
		// PROPAL_SENTBYMAIL
		// PROPAL_CLOSE_SIGNED
		// PROPAL_CLOSE_REFUSED
		// PROPAL_DELETE
		// LINEPROPAL_INSERT
		// LINEPROPAL_UPDATE
		// LINEPROPAL_DELETE

		// SupplierProposal
		// SUPPLIER_PROPOSAL_CREATE
		// SUPPLIER_PROPOSAL_MODIFY
		// SUPPLIER_PROPOSAL_VALIDATE
		// SUPPLIER_PROPOSAL_SENTBYMAIL
		// SUPPLIER_PROPOSAL_CLOSE_SIGNED
		// SUPPLIER_PROPOSAL_CLOSE_REFUSED
		// SUPPLIER_PROPOSAL_DELETE
		// LINESUPPLIER_PROPOSAL_INSERT
		// LINESUPPLIER_PROPOSAL_UPDATE
		// LINESUPPLIER_PROPOSAL_DELETE

		// Contracts
		// CONTRACT_CREATE
		// CONTRACT_MODIFY
		// CONTRACT_ACTIVATE
		// CONTRACT_CANCEL
		// CONTRACT_CLOSE
		// CONTRACT_DELETE
		// CONTRACT_SENTBYMAIL
		// LINECONTRACT_INSERT
		// LINECONTRACT_UPDATE
		// LINECONTRACT_DELETE

		// Bills
		// BILL_CREATE
		// BILL_MODIFY
		// BILL_VALIDATE
		// BILL_UNVALIDATE
		// BILL_SENTBYMAIL
		// BILL_CANCEL
		// BILL_DELETE
		// BILL_PAYED
		// LINEBILL_INSERT
		// LINEBILL_UPDATE
		// LINEBILL_DELETE

		// Supplier Bill
		// BILL_SUPPLIER_CREATE
		// BILL_SUPPLIER_UPDATE
		// BILL_SUPPLIER_DELETE
		// BILL_SUPPLIER_PAYED
		// BILL_SUPPLIER_UNPAYED
		// BILL_SUPPLIER_VALIDATE
		// BILL_SUPPLIER_UNVALIDATE
		// LINEBILL_SUPPLIER_CREATE
		// LINEBILL_SUPPLIER_UPDATE
		// LINEBILL_SUPPLIER_DELETE

		// Payments
		// PAYMENT_CUSTOMER_CREATE
		// PAYMENT_SUPPLIER_CREATE
		// PAYMENT_ADD_TO_BANK
		// PAYMENT_DELETE

		// Online
		// PAYMENT_PAYBOX_OK
		// PAYMENT_PAYPAL_OK
		// PAYMENT_STRIPE_OK

		// Donation
		// DON_CREATE
		// DON_UPDATE
		// DON_DELETE

		// Interventions
		// FICHINTER_CREATE
		// FICHINTER_MODIFY
		// FICHINTER_VALIDATE
		// FICHINTER_DELETE
		// FICHINTER_SENTBYMAIL
		// LINEFICHINTER_CREATE
		// LINEFICHINTER_UPDATE
		// LINEFICHINTER_DELETE

		// Members
		// MEMBER_CREATE
		// MEMBER_VALIDATE
		// MEMBER_SUBSCRIPTION
		// MEMBER_MODIFY
		// MEMBER_NEW_PASSWORD
		// MEMBER_RESILIATE
		// MEMBER_DELETE

		// Categories
		// CATEGORY_CREATE
		// CATEGORY_MODIFY
		// CATEGORY_DELETE
		// CATEGORY_SET_MULTILANGS

		// Projects
		// PROJECT_CREATE
		// PROJECT_MODIFY
		// PROJECT_DELETE
		// PROJECT_SENTBYMAIL
		// Project tasks
		// TASK_CREATE
		// TASK_MODIFY
		// TASK_DELETE

		// Task time spent
		// TASK_TIMESPENT_CREATE
		// TASK_TIMESPENT_MODIFY
		// TASK_TIMESPENT_DELETE

		// Shipping
		// SHIPPING_CREATE
		// SHIPPING_MODIFY
		// SHIPPING_VALIDATE
		// SHIPPING_SENTBYMAIL
		// SHIPPING_BILLED
		// SHIPPING_CLOSED
		// SHIPPING_REOPEN
		// SHIPPING_DELETE

		return 0;
	}

	/**
	 * Trigger ACTION_CREATE
	 * @param string        $action     Event action code
	 * @param ActionComm  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionCreate($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// does user have valid token
		// il faut rechercher le token du propriétaire de l'evt
		$staticuser = new User($this->db);
		$staticuser->fetch($object->userownerid);

		$client = getGoogleApiClient($staticuser);
		if ($client === false) {
			return 0;
		}

		googleapi_complete_label_and_note($object, $langs);
		if (empty($object->datef)) {
			$dateend = $object->datep;
		} else {
			$dateend = $object->datef;
		}
		//'DateTime' => '2017-04-03T10:00:00',
		//'DateTime' => '2019-09-14T22:02:34Z'
		//'TimeZone' => 'Pacific Standard Time',
		$dateend = $object->fulldayevent ? dol_print_date($dateend + 60, '%Y-%m-%dT00:00:00') : dol_print_date($dateend, '%Y-%m-%dT%H:%M:%S');

		$event = new \Google\Service\Calendar\Event(array(
			'summary' => $object->label,
			'location' => $object->location,
			'description' => $object->note,
			'start' => array(
				'dateTime' => dol_print_date($object->datep, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => $object->fulldayevent ? 'UTC' : 'Europe/Paris',
			),
			'end' => array(
				'dateTime' => $dateend,
				'timeZone' => $object->fulldayevent ? 'UTC' : 'Europe/Paris',
			),
			'recurrence' => array(
				//'RRULE:FREQ=DAILY;COUNT=2'
			),
			'attendees' => array(
				// array('email' => 'lpage@example.com'),
				// array('email' => 'sbrin@example.com'),
			),
			'reminders' => array(
				// 'useDefault' => false,
				// 'overrides' => array(
				// 	array('method' => 'email', 'minutes' => 24 * 60),
				// 	array('method' => 'popup', 'minutes' => 10),
				// ),
			),
		));

		$calendarId = 'primary';
		$service = new \Google\Service\Calendar($client);
		$event = $service->events->insert($calendarId, $event);

		// enregistrer l'id googleapi dans dolibarr (extrafield)
		$object->array_options['options_googleapi_EventId'] = $event->getId();
		$object->update($user, 1);
		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger ACTION_UPDATE
	 * @param string        $action     Event action code
	 * @param ActionComm  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionModify($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// il faut rechercher le token du propriétaire de l'evt
		$staticuser = new User($this->db);
		$staticuser->fetch($object->userownerid);
		// on complete ou pas?

		$client = getGoogleApiClient($staticuser);
		if ($client === false) {
			return 0;
		}

		if (empty($object->array_options['options_googleapi_EventId'])) {
			googleapi_complete_label_and_note($object, $langs);
		}
		if (empty($object->datef)) {
			$dateend = $object->datep;
		} else {
			$dateend = $object->datef;
		}
		//'DateTime' => '2017-04-03T10:00:00',
		//'DateTime' => '2019-09-14T22:02:34Z'
		//'TimeZone' => 'Pacific Standard Time',
		$dateend = $object->fulldayevent ? dol_print_date($dateend + 60, '%Y-%m-%dT00:00:00') : dol_print_date($dateend, '%Y-%m-%dT%H:%M:%S');

		dol_syslog('Sending new dates to googleapi ' . $object->datep, LOG_NOTICE);
		$event = new \Google\Service\Calendar\Event([
			'summary' => $object->label,
			'location' => $object->location,
			'description' => $object->note,
			'start' => [
				'dateTime' => dol_print_date($object->datep, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => $object->fulldayevent ? 'UTC' : 'Europe/Paris',
			],
			'end' => [
				'dateTime' => $dateend,
				'timeZone' => $object->fulldayevent ? 'UTC' : 'Europe/Paris',
			],
			'recurrence' => [
				//'RRULE:FREQ=DAILY;COUNT=2'
			],
			'attendees' => [
				// array('email' => 'lpage@example.com'),
				// array('email' => 'sbrin@example.com'),
			],
			'reminders' => [
				// 'useDefault' => false,
				// 'overrides' => array(
				// 	array('method' => 'email', 'minutes' => 24 * 60),
				// 	array('method' => 'popup', 'minutes' => 10),
				// ),
			],
		]);

		$calendarId = 'primary';
		$service = new \Google\Service\Calendar($client);
		if (empty($object->array_options['options_googleapi_EventId'])) {
			$event = $service->events->insert($calendarId, $event);
			// // enregistrer l'id googleapi dans dolibarr (extrafield)
			$object->fetch_optionals();
			$object->array_options['options_googleapi_EventId'] = $event->getId();
			$object->update($user, 1);
		} else {
			$event = $service->events->update($calendarId, $object->array_options['options_googleapi_EventId'], $event);
		}
		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger ACTION_DELETE
	 * @param string        $action     Event action code
	 * @param ActionComm  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionDelete($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// si on a un id dans l'ancienne copie de l'évènement
		if (!empty($object->oldcopy->array_options['options_googleapi_EventId'])) {
			// il faut rechercher le token du propriétaire de l'evt
			$staticuser = new User($this->db);
			$staticuser->fetch($object->userownerid);

			$client = getGoogleApiClient($staticuser);

			$calendarId = 'primary';
			$service = new \Google\Service\Calendar($client);
			try {
				$event = $service->events->delete($calendarId, $object->oldcopy->array_options['options_googleapi_EventId']);
			} catch (Exception $e) {
				dol_syslog($e->getMessage(), LOG_WARNING);
			}
		}
		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger ORDER_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Fichinter      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function fichinterSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "fichinter", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger ORDER_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Commande      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function orderSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "commande", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger ORDER_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Project      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function projectSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "project", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger PROPAL_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Propal        $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function propalSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "propal", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger SHIPPING_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Expedition    $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function shippingSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "shipping", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * function addEmailSent
	 * @param   int     $userid             User Id
	 * @param   int     $id                 Object Id
	 * @param   int     $socid              Thirdparty Id
	 * @param   string  $module             Module concerned
	 * @param   string  $googleapiMessageId GoogleApi message Id
	 * @return  void
	 */
	private function addEmailSent($userid, $id, $socid, $module, $googleapiMessageId)
	{
		$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'googleapi_emails_sent (';
		$sql .= 'userid, module, messageid, fk_object, fk_soc) VALUES (';
		$sql .= (int) $userid;
		$sql .= ', "' . $this->db->escape($module) . '"';
		$sql .= ', "' . $this->db->escape($googleapiMessageId) . '"';
		$sql .= ', ' . (int) $id;
		$sql .= ', ' . (int) $socid;
		$sql .= ')';
		$resql = $this->db->query($sql);
	}
}
