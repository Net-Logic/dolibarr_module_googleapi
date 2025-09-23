<?php
/* Copyright (C) 2004-2018  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2019-2021  Frédéric France         <frederic.france@netlogic.fr>
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
 *  \defgroup   googleapi     Module GoogleApi
 *  \brief      GoogleApi module descriptor.
 *
 *  \file       htdocs/googleapi/core/modules/modGoogleApi.class.php
 *  \ingroup    googleapi
 *  \brief      Description and activation file for module GoogleApi
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';


// phpcs:disable
/**
 *  Description and activation class for module GoogleApi
 */
class modGoogleApi extends DolibarrModules
{
	// phpcs:enable
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 135290;
		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'googleapi';
		// Family can be 'base' (core modules),'crm','financial','hr','projects',
		// 'products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "projects";
		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '01';
		// Gives the possibility for the module, to provide his own family info and position
		// of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleGoogleApiName' not found (GoogleApi is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		// Module description, used if translation string 'ModuleGoogleApiDesc' not found (GoogleApi is name of module).
		$this->description = "GoogleApiDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "GoogleApi description (Long)";

		$this->editor_name = 'Net Logic';
		$this->editor_url = 'https://netlogic.fr';

		// Possible values for version are: 'development', 'experimental', 'dolibarr',
		// 'dolibarr_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0.2';

		// Url to the file with your last numberversion of this module
		$this->url_last_version = 'https://wiki.netlogic.fr/versionmodule.php?module=googleapi';
		// Key used in llx_const table to save module status enabled/disabled
		// (where MYMODULE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		$this->picto = 'googleapi@googleapi';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = [
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module can send sms
			'sms' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => [
				// '/googleapi/css/googleapi.css.php',
			],
			// Set this to relative path of js file if module must load a js on all pages
			'js' => [
				'/googleapi/js/googleapi.js.php',
			],
			// Set here all hooks context managed by module. To find available hook context,
			// make a "grep -r '>initHooks(' *" on source code. You can also set hook context 'all'
			'hooks' => [
				'data' => [
					'main',
					//'mainloginpage',
					'toprightmenu',
					'usercard',
					'mail',
					'globalcard',
					//'invoicecard',
					'actioncard',
					'fileslib',
				],
				'entity' => $conf->entity,
			],
			// Set this to 1 if feature of module are opened to external users
			'moduleforexternal' => 0,
		];

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/googleapi/temp","/googleapi/subdir");
		$this->dirs = [
			"/googleapi/temp",
		];

		// Config pages. Put here list of php page, stored into googleapi/admin directory, to use to setup module.
		$this->config_page_url = ['setup.php@googleapi'];

		// Dependencies
		// A condition to hide module
		$this->hidden = false;
		// List of module class names as string that must be enabled if this module is enabled.
		// Example: ['always1'=>'modModuleToEnable1','always2'=>'modModuleToEnable2', 'FR1'=>'modModuleToEnableFR'...]
		$this->depends = ['always1' => 'modPrune'];
		// List of module class names as string to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = [];
		// List of module class names as string this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = [];
		$this->langfiles = ["googleapi@googleapi"];
		// Minimum version of PHP required by module
		$this->phpmin = [7, 4];
		// Minimum version of Dolibarr required by module
		$this->need_dolibarr_version = [15, 0];
		// Warning to show when we activate module. array('always'='text') or array('FR'='textfr','ES'='textes'...)
		$this->warnings_activation = [];
		// Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','ES'='textes'...)
		$this->warnings_activation_ext = [];
		//$this->automatic_activation = array('FR' => 'GoogleApiWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		// If true, can't be disabled
		//$this->always_enabled = true;

		// Constants
		$this->const = [];

		if (!isset($conf->googleapi) || !isset($conf->googleapi->enabled)) {
			$conf->googleapi = new stdClass();
			$conf->googleapi->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = [];

		// Dictionaries
		$this->dictionaries = [];

		// Boxes/Widgets
		// Add here list of php file(s) stored in googleapi/core/boxes that contains class to show a widget.
		$this->boxes = [];

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		$this->cronjobs = [
			0 => [
				'label' => 'Gestion des push GoogleApi',
				'jobtype' => 'method',
				'class' => '/googleapi/class/googleapi.class.php',
				'objectname' => 'GoogleApi',
				'method' => 'checkExpiredSheduledWatch',
				'parameters' => '',
				'comment' => 'Crée si nécessaire les notifications push de GoogleApi',
				'frequency' => 12,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => true,
			],
		];

		// Permissions
		$this->rights = [];    // Permission array used by this module
		$r = 0;
		// Permission id (must not be already used)
		$this->rights[$r][0] = $this->numero + $r;
		// Permission label
		$this->rights[$r][1] = 'Read myobject of Googleapi';
		// Permission by default for new user (0/1)
		$this->rights[$r][3] = 1;
		// In php code, permission will be checked by test if ($user->rights->googleapi->level1->level2)
		$this->rights[$r][4] = 'read';
		// In php code, permission will be checked by test if ($user->rights->googleapi->level1->level2)
		$this->rights[$r][5] = '';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Create/Update myobject of Googleapi';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Delete myobject of Googleapi';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'delete';
		$this->rights[$r][5] = '';


		// Main menu entries
		$this->menu = [];  // List of menus to add
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param   string  $options    Options when enabling module ('', 'noboxes')
	 * @return  int                 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/googleapi/sql/');
		if ($result < 0) {
			// Do not activate module if not allowed errors found on module SQL queries
			// (the _load_table run sql with run_sql with error allowed parameter to 'default')
			return -1;
		}
		// Create extrafields
		include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// actioncomm
		$extrafields->addExtraField('googleapi_EventId', "GoogleApi Id", 'varchar', $this->numero, 180, 'actioncomm', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		// user
		$extrafields->addExtraField('googleapi_Id', "GoogleApi Id", 'varchar', $this->numero, 64, 'user', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		$extrafields->addExtraField('googleapi_lastevent_sync', "GoogleApiLastEventSync", 'varchar', $this->numero + 1, 64, 'user', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		$extrafields->addExtraField('googleapi_email', "GoogleApiOwnerEmail", 'varchar', $this->numero + 2, 128, 'user', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		$extrafields->addExtraField('googleapi_calendarId', "GoogleApiCalendarId", 'varchar', $this->numero + 3, 128, 'user', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		$extrafields->addExtraField('googleapi_calendarTZ', "GoogleApiCalendarTZ", 'varchar', $this->numero + 4, 128, 'user', 0, 0, '', '', 1, '', '(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)', 0, '', '', 'googleapi@googleapi', 'isModEnabled("googleapi")');
		// thirdparty
		$result = $extrafields->addExtraField(
			'googleapiId',
			'GoogleApiIdId',
			'varchar',
			$this->numero,
			180,
			'societe',
			0,
			0,
			'',
			'',
			1,
			'',
			'(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)',
			0,
			'',
			'',
			'googleapi@googleapi',
			'isModEnabled("googleapi")'
		);
		// contact
		$result = $extrafields->addExtraField(
			'googleapiId',
			'GoogleApiIdId',
			'varchar',
			$this->numero,
			180,
			'contact',
			0,
			0,
			'',
			'',
			1,
			'',
			'(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)',
			0,
			'',
			'',
			'googleapi@googleapi',
			'isModEnabled("googleapi")'
		);
		// contact
		$result = $extrafields->addExtraField(
			'googleapiEtag',
			'GoogleApiEtag',
			'varchar',
			$this->numero + 10,
			255,
			'contact',
			0,
			0,
			'',
			'',
			1,
			'',
			'(empty($conf->global->GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG) ? 0:3)',
			0,
			'',
			'',
			'googleapi@googleapi',
			'isModEnabled("googleapi")'
		);

		$sql = [];

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param   string  $options    Options when enabling module ('', 'noboxes')
	 * @return  int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = [];

		return $this->_remove($sql, $options);
	}
}
