<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource tlTestCaseFilterByRequirementControl.class.php
 * @package    TestLink
 * @author     Tanguy Oger
 * @copyright  2006-2016, TestLink community
 * @link       http://testlink.sourceforge.net/
 * 
 *
 * This class extends tlFilterPanel for the specific use with test case tree by requirement.
 * It holds the logic to be used at GUI level to manage a common set of settings and filters for test cases.
 * 
 * This class is used from different navigator-frames (left frames with a test case tree in it)
 * with different modes for different features.
 * This is a little overview about its usage in TestLink:
 * 
 * 
 * - planAddTCNavigator.php/tpl in "plan_add_mode"
 *    --> add/remove test cases
 * 
 */

/*
 * --------------------------------------------------------
 * An important note on request-URL too large (BUGID 3516)
 * --------------------------------------------------------
 * see [TL_INSTALL]/docs/development/bugid-3516.txt
 *
 */ 

/**
 * This class extends tlFilterPanel for the specific use with the testcase tree.
 * It contains logic to be used at GUI level to manage
 * a common set of settings and filters for testcases.
 *
 * @package TestLink
 * @uses testplan
 * @uses exec_cf_mgr
 * @uses tlPlatform
 * @uses testcase
 */
class tlTestCaseFilterByRequirementControl extends tlFilterControl {

  
  public $req_mgr = null;
    
  /**
   * Testcase manager object.
   * Initialized not in constructor, only on first use to save resources.
   * @var testcase
   */
  private $tc_mgr = null;
  
  /**
   * Platform manager object.
   * Initialized not in constructor, only on first use to save resources.
   * @var tlPlatform
   */
  private $platform_mgr = null;
  
  /**
   * Testplan manager object.
   * Initialized not in constructor, only on first use to save resources.
   * @var testplan
   */
  private $testplan_mgr = null;
  
  /**
   * This array contains all possible filters.
   * It is used as a helper to iterate over all the filters in some loops.
   * It also sets options how and from where to load the parameters with
   * input fetching functions in init_args()-method.
   * Its keys are the names of the settings (class constants are used),
   * its values are the arrays for the input parser.
   * @var array
   */

  /* MAGIC NUMBERS are related to field size
   * filter_tc_id: 0,30 arbitrary
   * filter_bugs: 240 = 60 x 4 (60 bug_id size on execution_bugs table) 
   */
    private $all_filters = array(
      'filter_doc_id' => array("POST", tlInputParameter::STRING_N),
      'filter_title' => array("POST", tlInputParameter::STRING_N),
      'filter_status' => array("POST", tlInputParameter::ARRAY_STRING_N),
      'filter_type' => array("POST", tlInputParameter::ARRAY_INT),
      'filter_spec_type' => array("POST", tlInputParameter::ARRAY_INT),
      'filter_coverage' => array("POST", tlInputParameter::INT_N),
      'filter_relation' => array("POST", tlInputParameter::ARRAY_STRING_N),
      'filter_tc_id' => array("POST", tlInputParameter::STRING_N),
      'filter_custom_fields' => null, 'filter_result' => false);



  /**
   * This array is used as an additional security measure. It maps all available
   * filters to the mode in which they can be used. If a user tries to
   * enable filters in config.inc.php which are not defined inside this array,
   * this will be simply ignored instead of trying to initialize the filter
   * no matter wether it has been implemented or not.
   * The keys inside this array are the modes defined above as class constants.
   * So it can be checked if a filter is available in a given mode without
   * relying only on the config parameter.
   * @var array
   */
  private $mode_filter_mapping = array('plan_add_mode' => array('filter_tc_id',
                                                                'filter_testcase_name',
                                                                'filter_toplevel_testsuite',
                                                                'filter_keywords',
                                                                // 'filter_active_inactive',
                                                                'filter_importance',
                                                                'filter_execution_type',
                                                                'filter_workflow_status',
                                                                'filter_custom_fields'));

  /**
   * This array contains all possible settings. It is used as a helper
   * to later iterate over all possibilities in loops.
   * Its keys are the names of the settings, its values the arrays for the input parser.
   * @var array
   */
  private $all_settings = array(
    'setting_testplan' => array("REQUEST", tlInputParameter::INT_N),
    'setting_refresh_tree_on_action' => array("REQUEST", tlInputParameter::CB_BOOL),
		'setting_get_parent_child_relation' => array("REQUEST", tlInputParameter::CB_BOOL),
		'hidden_setting_get_parent_child_relation' => array("REQUEST", tlInputParameter::INT_N),
		'setting_testsgroupby' => array("REQUEST", tlInputParameter::INT_N));

  /**
   * This array is used to map the modes to their available settings.
   * @var array
   */
   
  private $mode_setting_mapping = array('plan_add_mode' => 
    array('setting_testplan','setting_refresh_tree_on_action',
					'setting_get_parent_child_relation',
					'hidden_setting_get_parent_child_relation',
					'setting_testsgroupby'));

  /**
   * The mode used. Depending on the feature for which this class will be instantiated.
   * This mode defines which filter configuration will be loaded from config.inc.php
   * and therefore which filters will be loaded and used for the templates.
   * Value has to be one of the class constants for mode, default is edit mode.
   * @var string
   */
  private $mode = 'plan_add_mode';


  /**
   * Options to be used accordin to $this->mode, to build tree
   * @var array
   */
  private $treeOpt = array();


  /**
   * The token that will be used to identify the relationship between left frame
   * (with navigator) and right frame (which displays execution of test case e.g.) in session.
   * @var string
   */
  public $form_token = null;
  
  
  
  /**
   *
   * @param database $dbHandler
   * @param string $mode can be plan_add_mode, depending on usage
   */
  public function __construct(&$dbHandler, $mode = 'plan_add_mode') 
  {
    // set mode to define further actions before calling parent constructor
    $this->mode = array_key_exists($mode,$this->mode_filter_mapping) ? $mode : 'edit_mode';

    // Call to constructor of parent class tlFilterControl.
    // This already loads configuration and user input
    // and does all the remaining necessary method calls,
    // so no further method call is required here for initialization.
    parent::__construct($dbHandler);
    $this->req_mgr = new requirement_mgr($this->db);
    $this->cfield_mgr = &$this->req_mgr->cfield_mgr;  

    // moved here from parent::__constructor() to be certain that 
    // all required objects has been created
    $this->init_filters();

    $this->initTreeOptions($this->mode);
    
    // delete any filter settings that may be left from previous calls in session
    // Session data has been designed to provide an unidirectional channel
    // between the left pane where tree lives and right pane.
    // That's why delete each time our OWN session data. 
    $this->delete_own_session_data();  
    $this->delete_old_session_data();
    
    $this->save_session_data();

  }

  /**
   * 
   * 
   */
  public function __destruct() 
  {
    parent::__destruct(); //destroys testproject manager
    
    unset($this->tc_mgr);
    unset($this->testplan_mgr);
    unset($this->platform_mgr);
    unset($this->cfield_mgr);
  }
  
  /**
   * Reads the configuration from the configuration file specific for test cases,
   * additionally to those parts of the config which were already loaded by parent class.
   * @return bool
   */
  protected function read_config() 
  {
    // some configuration reading already done in parent class
    parent::read_config();

    // load configuration for active mode only
     $this->configuration = config_get('tree_filter_cfg')->requirements;

    // load also exec config - it is not only needed in exec mode
    $this->configuration->exec_cfg = config_get('exec_cfg');

    // some additional testcase configuration
    $this->configuration->tc_cfg = config_get('testcase_cfg'); 

    // load req and req spec config (for types, filters, status, ...)
    $this->configuration->req_cfg = config_get('req_cfg');
    $this->configuration->req_spec_cfg = config_get('req_spec_cfg');
    
    // is switch filter mode enabled?
    $this->filter_mode_choice_enabled = false;
    switch( $this->mode )
    {
      case 'edit_mode':
      break;

      default:
        if (isset($this->configuration->advanced_filter_mode_choice) && 
            $this->configuration->advanced_filter_mode_choice == ENABLED) 
        {
          $this->filter_mode_choice_enabled = true;
        } 
      break;
    }

    return tl::OK;
  } // end of method

  /**
   * Does what init_args() usually does in all scripts: Reads the user input
   * from request ($_GET and $_POST). 
   * Later configuration, settings and filters get modified according to that user input.
   */
  protected function init_args() 
  {
    // some common user input is already read in parent class
    parent::init_args();

    // add settings and filters to parameter info array for request parsers
    $params = array();

    foreach ($this->all_settings as $name => $info) {
      if (is_array($info)) {
        $params[$name] = $info;
      }
    }
     
    foreach ($this->all_filters as $name => $info) {
      if (is_array($info)) {
        $params[$name] = $info;
      }
    }
    
    I_PARAMS($params, $this->args);
  } // end of method

  /**
   * Initializes all settings.
   * Iterates through all available settings and adds an array to $this->settings
   * for the active ones, sets the rest to false so this can be
   * checked from templates and elsewhere.
   * Then calls the initializing method for each still active setting.
   */
  protected function init_settings() 
  {
    $at_least_one_active = false;

    foreach ($this->all_settings as $name => $info) 
    {
      $init_method = "init_$name";
      if (in_array($name, $this->mode_setting_mapping[$this->mode]) && 
        method_exists($this, $init_method)) 
      {
        // is valid, configured, exists and therefore can be used, so initialize this setting
        $this->$init_method();
        $at_least_one_active = true;
      } 
      else 
      {
        // is not needed, simply deactivate it by setting it to false in main array
        $this->settings[$name] = false;
      }
    }
    
    // special situations 
    // the build setting is in plan mode only needed for one feature
    if ($this->mode == 'plan_mode' && 
        ($this->args->feature != 'tc_exec_assignment' && $this->args->feature != 'test_urgency') )
    {
      $this->settings['setting_build'] = false;
      $this->settings['setting_platform'] = false;
    }
  
    // if at least one active setting is left to display, switch settings panel on
    if ($at_least_one_active) 
    {
      $this->display_settings = true;
    }
  }

  /**
   * Initialize all filters. (called by parent::__construct())
   * I'm double checking here with loaded configuration _and_ additional array
   * $mode_filter_mapping, set according to defined mode, because this can avoid errors in case
   * when users try to enable a filter in config that doesn't exist for a mode.
   * Effect: Only existing and implemented filters can be activated in config file.
   */
  protected function init_filters() 
  {
    // iterate through all filters and activate the needed ones
    if ($this->configuration->show_filters == ENABLED) 
    {
      foreach ($this->all_filters as $name => $info) 
      {
        $init_method = "init_$name";
        if (method_exists($this, $init_method) && $this->configuration->{$name} == ENABLED) 
        {
          $this->$init_method();
          $this->display_req_filters = true;
        } 
        else 
        {
          // is not needed, deactivate filter by setting it to false in main array
          // and of course also in active filters array
          $this->filters[$name] = false;
          $this->active_filters[$name] = null;
        }
      }
      
    } 
    else 
    {
      $this->display_req_filters = false;
    }
  } // end of method

  /**
   * This method returns an object or array, containing all selections chosen
   * by the user for filtering.
   * 
   * @return mixed $value Return value is either an array or stdClass object,
   * depending on active mode. It contains all filter values selected by the user.
   */
  protected function get_active_filters() 
  {
    static $value = null; // serves as a kind of cache if method is called more than once
        
    // convert array to stcClass if needed
    if (!$value) 
    {
      switch ($this->mode) 
      {
        case 'execution_mode':
        case 'plan_mode':
          // these features are generating an exec tree,
          // they need the filters as a stdClass object
          $value = (object)$this->active_filters;
        break;
        
        default:
          // otherwise simply return the array as-is
          $value = $this->active_filters;
        break;
      }
    }
    
    return $value;
  } // end of method

  /**
   * 
   * 
   */
  public function set_testcases_to_show($value = null) 
  {
    // update active_filters
    if (!is_null($value)) {
      $this->active_filters['testcases_to_show'] = $value;
    }
    
    // Since a new filter in active_filters has been set from outside class after
    // saving of session data has already happened in constructor, 
    // we explicitly update data in session after this change here.
    $this->save_session_data();
  }
  
  /**
   * Active filters will be saved to $_SESSION. 
   * If there already is data for the active mode and token, it will be overwritten.
   * This data will be read from pages in the right frame.
   * This solves the problems with too long URLs.
   * See issue 3516 in Mantis for a little bit more information/explanation.
   * The therefore caused new problem that would arise now if
   * a user uses the same feature simultaneously in multiple browser tabs
   * is solved be the additional measure of using a form token.
   * 
   * @author Andreas Simon
   * @return $tl::OK
   */
  public function save_session_data() {   
    if (!isset($_SESSION[$this->mode]) || is_null($_SESSION[$this->mode]) || !is_array($_SESSION[$this->mode])) {
      $_SESSION[$this->mode] = array();
    }
    

    $_SESSION[$this->mode][$this->form_token] = $this->active_filters;
    $_SESSION[$this->mode][$this->form_token]['timestamp'] = time();

    // Need to add to cache also some settings
    // setting_testplan
    // setting_platform
    // setting_build
    // setting_refresh_tree_on_action
    // setting_testsgroupby
    // setting_exec_tree_counters_logic
    $s2a = array('testplan','platform','build',
                 'refresh_tree_on_action','testsgroupby',
                 'exec_tree_counters_logic');
    foreach ($s2a as $stk) {
      $ki = 'setting_' . $stk;
      $_SESSION[$this->mode][$this->form_token][$ki] = $this->args->$ki;
    } 
    return tl::OK;
  }
  
  /**
   * Old filter data for active mode will be deleted from $_SESSION.
   * It happens automatically after a session has expired and a user therefore
   * has to log in again, but here we can configure an additional time limit
   * only for this special filter part in session data.
   * 
   * @author Andreas Simon
   * @param int $token_validity_duration data older than given timespan will be deleted
   */
  public function delete_old_session_data($token_validity_duration = 0) 
  {
 
    // TODO this duration could maybe also be configured in config/const.inc.php
    
    // how long shall the data remain in session before it will be deleted?
    if (!is_numeric($token_validity_duration) || $token_validity_duration <= 0) {
      $token_validity_duration = 60 * 60 * 1; // one hour as default
    }
    
    // delete all tokens from session that are older than given age
    if (isset($_SESSION[$this->mode]) && is_array($_SESSION[$this->mode])) {
      foreach ($_SESSION[$this->mode] as $token => $data) {
        if ($data['timestamp'] < (time() - $token_validity_duration)) {
          unset($_SESSION[$this->mode][$token]);  // too old, delete!
        }
      }
    }
  }
  
  /**
   * 
   * 
   */
  public function delete_own_session_data() 
  {
    if (isset($_SESSION[$this->mode]) && isset($_SESSION[$this->mode][$this->form_token])) 
    {
      unset($_SESSION[$this->mode][$this->form_token]);
    }
  }
  
  /**
   * Generates a form token, which will be used to identify the relationship
   * between left navigator-frame with its settings and right frame.
   */
  protected function generate_form_token() 
  {
    // Notice: I am just generating an integer here for the token.
    // Since this is not any security relevant stuff like a password hash or similar,
    // but only a means to separate multiple tabs a single user opens, this should suffice.
    // If we should some day decide that an integer is not enough,
    // we just have to change this one method and everything will still work.
    
    $min = 1234567890; // not magic, just some large number so the tokens don't get too short 
    $max = mt_getrandmax();
    $token = 0;
    
    // generate new tokens until we find one that doesn't exist yet
    do {
      $token = mt_rand($min, $max);
    } while (isset($_SESSION[$this->mode][$token]));
    
    $this->form_token = $token;
  }
  
  /**
   * Active filters will be formatted as a GET-argument string.
   * 
   * @return string $string the formatted string with active filters
   */
  public function get_argument_string() 
  {
    static $string = null; // cache for repeated calls of this method
    
    if (!$string) 
    {
      $string = '';

      // important: the token with which the page in right frame can access data in session
      $string .= '&form_token=' . $this->form_token;

      $key2loop = array('setting_build','setting_platform');
      foreach($key2loop as $kiwi)
      {
        if($this->settings[$kiwi]) 
        {
          $string .= "&{$kiwi}={$this->settings[$kiwi]['selected']}";
        }
        
      }     
      if ($this->active_filters['filter_priority'] > 0) 
      {
        $string .= '&filter_priority=' . $this->active_filters['filter_priority'];
      }
    
      
      $keyword_list = null;
      if (is_array($this->active_filters['filter_keywords'])) 
      {
        $keyword_list = implode(',', $this->active_filters['filter_keywords']);
      } 
      else if ($this->active_filters['filter_keywords']) 
      {
        $keyword_list = $this->active_filters['filter_keywords'];
      }     
      
      
      // Need to undertand why for other filters that also are array
      // we have choosen to serialize, and here not.
      // may be to avoid more refactoring
      if ($keyword_list) 
      {
        $string .= '&filter_keywords=' . $keyword_list . 
                   '&filter_keywords_filter_type=' . 
                   $this->active_filters['filter_keywords_filter_type'];
      }
      
      // Using serialization      
      if ($this->active_filters['filter_assigned_user']) 
      {
        $string .= '&filter_assigned_user='. json_encode($this->active_filters['filter_assigned_user']) .
                   '&filter_assigned_user_include_unassigned=' . 
                   ($this->active_filters['filter_assigned_user_include_unassigned'] ? '1' : '0');
      }
      
      if ($this->active_filters['filter_result_result']) 
      {
        $string .= '&filter_result_result=' . json_encode($this->active_filters['filter_result_result']) .
                   '&filter_result_method=' . $this->active_filters['filter_result_method'] .
                   '&filter_result_build=' .  $this->active_filters['filter_result_build'];
      }

      if( !is_null($this->active_filters['filter_bugs']))
      {
        $string .= '&' . http_build_query( array('filter_bugs' => $this->active_filters['filter_bugs']));  
      }  

    }
    
    return $string;
  }
  
  /**
   * Build the tree menu for generation of JavaScript test case tree.
   * Depending on mode and user's selections in user interface, 
   * either a completely filtered tree will be build and returned,
   * or only the minimal necessary data to "lazy load" 
   * the objects in the tree by later Ajax calls.
   * No return value - all variables will be stored in gui object
   * which is passed by reference.
   * 
   * @author Andreas Simon
   * @param object $gui Reference to GUI object (data will be written to it)
   */
  public function build_tree_menu(&$gui) 
  {
    $tree_menu = null;
    $filters = $this->get_active_filters();
    $loader = '';
    $children = "[]";
    $cookie_prefix = '';

    // by default, disable drag and drop, then later enable if needed
    $drag_and_drop = new stdClass();
    $drag_and_drop->enabled = false;
    $drag_and_drop->BackEndUrl = '';
    $drag_and_drop->useBeforeMoveNode = FALSE;
    if (!$this->testproject_mgr) 
    {
      $this->testproject_mgr = new testproject($this->db);
    }
    $tc_prefix = $this->testproject_mgr->getTestCasePrefix($this->args->testproject_id);

    switch ($this->mode) 
    {     
      case 'plan_add_mode':
        // improved cookiePrefix - 
        // tree in plan_add_mode is only used for add/removed test cases features 
        // and shows all test cases defined within test project, 
        // but as test cases are added to a specified test plan -> store state for each test plan
        // 
        // usage of wrong values in $this->args->xyz for cookiePrefix instead of correct 
        // values in $filters->setting_xyz
        $cookie_prefix = "add_remove_tc_tplan_id_{$filters['setting_testplan']}_";

		// get filter mode
        $key = 'setting_testsgroupby';
        $mode = $this->args->$key;
		

        if ($this->do_filtering)
        {
          $ignore_inactive_testcases = DO_NOT_FILTER_INACTIVE_TESTCASES;
          $ignore_active_testcases = DO_NOT_FILTER_INACTIVE_TESTCASES;
                    
          $options = array('forPrinting' => NOT_FOR_PRINTING,
                           'hideTestCases' => HIDE_TESTCASES,
                           'tc_action_enabled' => ACTION_TESTCASE_DISABLE,
                           'viewType' => 'testSpecTreeForTestPlan',
                           'ignore_inactive_testcases' => $ignore_inactive_testcases,
                           'ignore_active_testcases' => $ignore_active_testcases);

			if ($mode == 'mode_req_coverage')
			{			

				$options = array('for_printing' => NOT_FOR_PRINTING,'exclude_branches' => null);

				$tree_menu = generateTestReqCoverageTree($this->db,
													$this->args->testproject_id,
													$this->args->testproject_name,
													$gui->menuUrl, $filters, $options);
												
			}
			
			$root_node = $tree_menu->rootnode;
			$children = $tree_menu->menustring ? $tree_menu->menustring : "[]";
        } 
        else 
        {
			  if ($mode == 'mode_req_coverage')
			  {
					  $loader = $gui->basehref . 'lib/ajax/getreqcoveragenodes.php?mode=reqspec&' .
									 "root_node={$this->args->testproject_id}";

					  $req_qty = count($this->testproject_mgr->get_all_requirement_ids($this->args->testproject_id));

					  $root_node = new stdClass();
					  $root_node->href = "javascript:EP({$this->args->testproject_id})";
					  $root_node->id = $this->args->testproject_id;
					  $root_node->name = $this->args->testproject_name . " ($req_qty)";
					  $root_node->testlink_node_type = 'testproject';
			}
        }
      break;
    }
    
    $gui->tree = $tree_menu;
    
    $gui->ajaxTree = new stdClass();
    $gui->ajaxTree->loader = $loader;
    $gui->ajaxTree->root_node = $root_node;
    $gui->ajaxTree->children = $children;
    $gui->ajaxTree->cookiePrefix = $cookie_prefix;
    $gui->ajaxTree->dragDrop = $drag_and_drop;
  } // end of method
  
  /**
   * 
   * 
   */
  private function init_setting_refresh_tree_on_action() 
  {

    $key = 'setting_refresh_tree_on_action';
    $hidden_key = 'hidden_setting_refresh_tree_on_action';
    $selection = 0;

    $this->settings[$key] = array();
    $this->settings[$key][$hidden_key] = false;

    // look where we can find the setting - POST, SESSION, config?
    if (isset($this->args->{$key})) {
      $selection = 1;
    } else if (isset($this->args->{$hidden_key})) {
      $selection = 0;
    } else if (isset($_SESSION[$key])) {
      $selection = $_SESSION[$key];
    } else {
      $spec_cfg = config_get('spec_cfg');
      $selection = ($spec_cfg->automatic_tree_refresh > 0) ? 1 : 0;
    }
    
    $this->settings[$key]['selected'] = $selection;
    $this->settings[$key][$hidden_key] = $selection;
    $_SESSION[$key] = $selection;   
  } // end of method

  /**
   * 
   * 
   */
  private function init_setting_get_parent_child_relation() 
  {
    $key = 'setting_get_parent_child_relation';
    $hidden_key = 'hidden_setting_get_parent_child_relation';
    $selection = 0;

    $this->settings[$key] = array();
    $this->settings[$key][$hidden_key] = false;

    // look where we can find the setting - POST, SESSION
    if (isset($this->args->{$key})) {
      $selection = 1;
	} else if (isset($this->args->{$hidden_key})) {
      $selection = 0;
    } else if (isset($_SESSION[$key])) {
      $selection = $this->settings[$key];
    } else {
      $selection = 0;
    }
    
    $this->settings[$key]['selected'] = $selection;
    $this->settings[$key][$hidden_key] = $selection;
    $_SESSION[$key] = $selection;   
  } // end of method

  
  // CRITIC -> IVU REMOVE ACCESS TO TESTPLAB FROM SESSION
  private function init_setting_testplan()  {
    if (is_null($this->testplan_mgr))  {
      $this->testplan_mgr = new testplan($this->db);
    }
    
    $this->args->reset_filters = true;

    $testplans = $this->user->getAccessibleTestPlans($this->db, $this->args->testproject_id);
    
    $tplan_id = $this->args->testplan_id;
    if (0 == $tplan_id || $this->args->setting_testplan >0) {
      $tplan_id = $this->args->setting_testplan;
      $this->args->testplan_id = $this->args->setting_testplan;
    }

    $info = $this->testplan_mgr->get_by_id($tplan_id);
    $this->args->testplan_name = $info['name'];
    $key = 'setting_testplan';
    $this->args->{$key} = $info['id'];
    $this->settings[$key]['selected'] = $info['id'];


    // echo 'MODE IS:' . $this->mode;
    // Reset filters
    // This depends on mode of operation
    // execution_mode -> exec
    // plan_add_mode -> add test cases
    // plan_mode -> assign test case execution
    //              set urgent test cases
    //              update linked test case versions
    //
    switch ($this->mode) {
      case 'plan_add_mode':
      break;


    }


    // Final filtering based on mode of operation
    //
    // Now get all selectable testplans for the user to display.
    // For execution:
    // 
    // For assign test case execution feature:
    //     don't take testplans into selection which 
    //     have no (active/open) builds!
    //
    // For plan add mode: 
    //     add every plan no matter if he has builds or not.
    //
    $addToPlanTask = $this->mode == 'plan_add_mode' || 
                     ($this->mode == 'plan_mode' && 
                      $this->args->feature != 'tc_exec_assignment');
    foreach ($testplans as $plan) {
      $recheckIt = false;
      if ($addToPlanTask == false) {
        $builds = $this->testplan_mgr->get_builds($plan['id'],testplan::GET_ACTIVE_BUILD,testplan::GET_OPEN_BUILD);
        $recheckIt =  (is_array($builds) && count($builds));
      }
      
      if ($addToPlanTask || $recheckIt) {
        $this->settings[$key]['items'][$plan['id']] = $plan['name'];
      }
    }
  }

  
    /**
   *
   */ 
  protected function init_setting_testsgroupby()
  {
	$key = 'setting_testsgroupby';
	
	// now load info from session
	$mode = (isset($_REQUEST[$key])) ? $_REQUEST[$key] : 0;
	$this->args->testsgroupedby_mode = $mode;
	$this->args->{$key} = $mode;
	$this->settings[$key]['selected'] = $mode;
	
	$this->settings[$key]['items']['mode_test_suite'] = lang_get('mode_test_suite');
	$this->settings[$key]['items']['mode_req_coverage'] = lang_get('mode_req_coverage');
  } // end of method

  /*
  *
  */
  private function init_filter_tc_id() 
  {
    $key = 'filter_tc_id';
    $selection = $this->args->{$key};
    
    if (!$this->testproject_mgr) {
      $this->testproject_mgr = new testproject($this->db);
    }
    
    $tc_cfg = config_get('testcase_cfg');
    $tc_prefix = $this->testproject_mgr->getTestCasePrefix($this->args->testproject_id);
    $tc_prefix .= $tc_cfg->glue_character;
    
    if (!$selection || $selection == $tc_prefix || $this->args->reset_filters) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection ? $selection : $tc_prefix);
    $this->active_filters[$key] = $selection;
  } // end of method
  
  /**
   * 
   * 
   */
  private function init_filter_testcase_name() {
    $key = 'filter_testcase_name';
    $selection = $this->args->{$key};
    
    if (!$selection || $this->args->reset_filters) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection);
    $this->active_filters[$key] = $selection;
  } // end of method


  /**
   * 
   * 
   */
  private function init_filter_toplevel_testsuite() 
  {
    if (!$this->testproject_mgr) 
    {
      $this->testproject_mgr = new testproject($this->db);
    }
    $key = 'filter_toplevel_testsuite';
    $first_level_suites = $this->testproject_mgr->get_first_level_test_suites($this->args->testproject_id,
                                                                              'smarty_html_options');
    
    $selection = $this->args->{$key};
    if (!$selection || $this->args->reset_filters) 
    {
      $selection = null;
    } 
    else 
    {
      $this->do_filtering = true;
    }
    
    // this filter should only be visible if there are any top level testsuites
    $this->filters[$key] = null;
    if ($first_level_suites) 
    {     
      $this->filters[$key] = array('items' => array(0 => ''),
                                   'selected' => $selection,
                                   'exclude_branches' => array());
    
      foreach ($first_level_suites as $suite_id => $suite_name) 
      {
        $this->filters[$key]['items'][$suite_id] = $suite_name;
        if ($selection && $suite_id != $selection) 
        {
          $this->filters[$key]['exclude_branches'][$suite_id] = 'exclude_me';
        }
      }
      
      // Important: This is the only case in which active_filters contains the items
      // which have to be deleted from tree, instead of the other way around.
      $this->active_filters[$key] = $this->filters[$key]['exclude_branches'];
    } 
    else 
    {
      $this->active_filters[$key] = null;
    }   
  } // end of method

  /**
   * 
   * @internal revision
   * @since 1.9.13
   * mode this affect domain
   */
  private function init_filter_keywords() 
  {
    $key = 'filter_keywords';
    $type = 'filter_keywords_filter_type';
    $this->filters[$key] = false;
    $keywords = null;
    $l10n = init_labels(array('logical_or' => null,'logical_and' => null, 'not_linked' => null));


    switch ($this->mode) 
    {
      case 'edit_mode':
      case 'plan_add_mode':
        // we need the keywords for the whole testproject
        if (!$this->testproject_mgr) 
        {
          $this->testproject_mgr = new testproject($this->db);
        }
        $keywords = $this->testproject_mgr->get_keywords_map($this->args->testproject_id);
      break;

      default:
        // otherwise (not in edit mode), we want only keywords assigned to testplan
        if (!$this->testplan_mgr) 
        {
          $this->testplan_mgr = new testplan($this->db);
        }
        $tplan_id = $this->settings['setting_testplan']['selected'];
        $keywords = $this->testplan_mgr->get_keywords_map($tplan_id, ' ORDER BY keyword ');
      break;
    }

    $special = array('domain' => array(), 'filter_mode' => array());
    switch($this->mode)
    {
      case 'edit_mode':
        $special['domain'] = array(-1 => $this->option_strings['without_keywords'], 
                                    0 => $this->option_strings['any']);       
        $special['filter_mode'] = array('NotLinked' => $l10n['not_linked']);                               
      break;

      case 'execution_mode':
      case 'plan_add_mode':
      case 'plan_mode':
      default:
        $special['domain'] = array(0 => $this->option_strings['any']);  
        $special['filter_mode'] = array();
      break;  
    }

    $selection = $this->args->{$key};
    $type_selection = $this->args->{$type};
    
    // are there any keywords?
    if (!is_null($keywords) && count($keywords)) 
    {
      $this->filters[$key] = array();

      if (!$selection || !$type_selection || $this->args->reset_filters) 
      {
        // default values for filter reset
        $selection = null;
        $type_selection = 'Or';
      } 
      else 
      {
        $this->do_filtering = true;
      }
      
      // data for the keywords themselves     
      $this->filters[$key]['items'] = $special['domain'] + $keywords;
      $this->filters[$key]['selected'] = $selection;
      $this->filters[$key]['size'] = min(count($this->filters[$key]['items']),
                                         self::ADVANCED_FILTER_ITEM_QUANTITY);

      // additional data for the filter type (logical and/or)
      $this->filters[$key][$type] = array();
      $this->filters[$key][$type]['items'] = array('Or' => $l10n['logical_or'],
                                                   'And' => $l10n['logical_and']) +
                                             $special['filter_mode'];
      $this->filters[$key][$type]['selected'] = $type_selection;
    }
    
    // set the active value to filter
    // delete keyword filter if "any" (0) is part of the selection - regardless of filter mode
    if (is_array($this->filters[$key]['selected']) && in_array(0, $this->filters[$key]['selected'])) 
    {
      $this->active_filters[$key] = null;
    } 
    else 
    {
      $this->active_filters[$key] = $this->filters[$key]['selected'];
    }
    $this->active_filters[$type] = $selection ? $type_selection : null;
  } 



  // TICKET 4353: added active/inactive filter
  private function init_filter_active_inactive() 
  {
    $key = 'filter_active_inactive';
        
    $items = array(DO_NOT_FILTER_INACTIVE_TESTCASES => $this->option_strings['any'],
                   IGNORE_INACTIVE_TESTCASES => lang_get('show_only_active_testcases'),
                   IGNORE_ACTIVE_TESTCASES => lang_get('show_only_inactive_testcases'));
        
    $selection = $this->args->{$key};
        
    if (!$selection || $this->args->reset_filters) 
    {
      $selection = null;
    } 
    else 
    {
      $this->do_filtering = true;
    }

    $this->filters[$key] = array('items' => $items, 'selected' => $selection);
    $this->active_filters[$key] = $selection;
  }
    

  /**
   *
   */
  private function init_filter_importance() 
  {
    // show this filter only if test priority management is enabled
    $key = 'filter_importance';
    $this->active_filters[$key] = null;
    $this->filters[$key] = false;

    if (!$this->testproject_mgr) 
    {
      $this->testproject_mgr = new testproject($this->db);
    }
    $tp_info = $this->testproject_mgr->get_by_id($this->args->testproject_id);
    $enabled = $tp_info['opt']->testPriorityEnabled;

    if ($enabled) 
    {
      $selection = $this->args->{$key};
      if (!$selection || $this->args->reset_filters) 
      {
        $selection = null;
      } 
      else 
      {
        $this->do_filtering = true;
      }


      $this->filters[$key] = array('selected' => $selection);
  
      // Only drawback: no new user defined importance can be managed
      //                may be is a good design choice
      $this->filters[$key]['items'] = array(0 => $this->option_strings['any'],
                                            HIGH => lang_get('high_importance'), 
                                            MEDIUM => lang_get('medium_importance'), 
                                            LOW => lang_get('low_importance'));
    
      $this->filters[$key]['size'] = sizeof($this->filters[$key]['items']);
      $this->active_filters[$key] = $selection;
    }
  }
  

  /**
   *
   *
   */  
  private function init_filter_priority() 
  {
    // This is a special case of filter: the menu items don't get initialized here,
    // they are available as a global smarty variable. So the only thing to be managed
    // here is the selection by user.
    $key = 'filter_priority';
    
    if (!$this->testproject_mgr) 
    {
      $this->testproject_mgr = new testproject($this->db);
    }
    
    $tp_info = $this->testproject_mgr->get_by_id($this->args->testproject_id);
    $enabled = $tp_info['opt']->testPriorityEnabled;
        
    $this->active_filters[$key] = null;
    $this->filters[$key] = false;
    
    if ($enabled) 
    {
      $selection = $this->args->{$key};
      if (!$selection || $this->args->reset_filters) 
      {
        $selection = null;
      } 
      else 
      {
        $this->do_filtering = true;
      }
  
      $this->filters[$key] = array('selected' => $selection);
      $this->active_filters[$key] = $selection;
    }   
  } // end of method

  /**
   *
   */
  private function init_filter_execution_type() 
  {
    if (!$this->tc_mgr) {
      $this->tc_mgr = new testcase($this->db);
    }
    $key = 'filter_execution_type';

    $selection = $this->args->{$key};
    // handle filter reset
    if (!$selection || $this->args->reset_filters) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('items' => array(), 'selected' => $selection);

    // load available execution types
    // add "any" string to these types at index 0 as default selection
    $this->filters[$key]['items'] = $this->tc_mgr->get_execution_types();
    $this->filters[$key]['items'] = array(0 => $this->option_strings['any'])
                                          + $this->filters[$key]['items'];
    
    $this->active_filters[$key] = $selection;
  } // end of method

  /**
   *
   */
  private function init_filter_assigned_user() 
  {
    if (!$this->testproject_mgr) {
      $this->testproject_mgr = new testproject($this->db);
    }

    $key = 'filter_assigned_user';
    $unassigned_key = 'filter_assigned_user_include_unassigned';
    $tplan_id = $this->settings['setting_testplan']['selected'];

    // set selection to default (any), only change if value is sent by user and reset is not requested
    $selection = $this->args->{$key};
    if (!$selection || $this->args->reset_filters) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }

    $tproject_info = $this->testproject_mgr->get_by_id($this->args->testproject_id);

    $all_testers = getTestersForHtmlOptions($this->db, $tplan_id, $tproject_info, null,
                                          array(TL_USER_ANYBODY => $this->option_strings['any'],
                                                TL_USER_NOBODY => $this->option_strings['none'],
                                                TL_USER_SOMEBODY => $this->option_strings['somebody']),
                                          'any');
    $visible_testers = $all_testers;
    
    // in execution mode the rights of the user have to be regarded
    if ($this->mode == 'execution_mode') 
    {
      $role = $this->user->getEffectiveRole($this->db, $this->args->testproject_id, $tplan_id);
      
      $simple_tester_roles = array_flip($this->configuration->exec_cfg->simple_tester_roles);
      
      // check the user's rights to see what he may do
      $right_to_execute = $role->hasRight('testplan_execute');
      $right_to_manage = $role->hasRight('testplan_planning');
      
      $simple = false;
      if (isset($simple_tester_roles[$role->dbID]) || ($right_to_execute && !$right_to_manage)) {
        // user is only simple tester and may not see/execute everything
        $simple = true;
      }

      $view_mode = $simple ? $this->configuration->exec_cfg->view_mode->tester : 'all';
      
      if ($view_mode != 'all') {
        $visible_testers = (array)$this->user->getDisplayName();
        $selection = (array)$this->user->dbID;
      }

      // re-enable option "user_filter_default"
      if (!$selection && $this->configuration->exec_cfg->user_filter_default == 'logged_user') {
        $selection = (array)$this->user->dbID;
      }
    }
    
    $this->filters[$key] = array('items' => $visible_testers,
                                 'selected' => $selection,
                                 $unassigned_key => $this->args->{$unassigned_key});
    
    // which value shall be passed to tree generation class?
    
    if ((is_array($selection) && in_array(TL_USER_ANYBODY, $selection))
    || ($selection == TL_USER_ANYBODY)) {
      // delete user assignment filter if "any user" is part of the selection
      $this->active_filters[$key] = null;
      $this->active_filters[$unassigned_key] = 0;
    }
    
    if (is_array($selection)) {
      // get keys of the array as values
      $this->active_filters[$key] = array_flip($selection);
      foreach ($this->active_filters[$key] as $user_key => $user_value) {
        $this->active_filters[$key][$user_key] = $user_key;
      }
      $this->active_filters[$unassigned_key] = $this->filters[$key][$unassigned_key];
    }
  } // end of method


  /**
   *
   */ 
  private function init_filter_result() 
  {
    $result_key = 'filter_result_result';
    $method_key = 'filter_result_method';
    $build_key = 'filter_result_build';
    
    if (is_null($this->testplan_mgr)) 
    {
      $this->testplan_mgr = new testplan($this->db);
    }
    $tplan_id = $this->settings['setting_testplan']['selected'];

    $this->configuration->results = config_get('results');

    // determine, which config to load and use for filter methods - depends on mode!
    $cfg = ($this->mode == 'execution_mode') ? 
           'execution_filter_methods' : 'execution_assignment_filter_methods';
    $this->configuration->filter_methods = config_get($cfg);

    //
    // CRITIC - Differences bewteen this configuration and
    // (file const.inc.php)
    // $tlCfg->execution_filter_methods['default_type'] 
    // $tlCfg->execution_assignment_filter_methods['default_type']
    // 
    // Will create issues: you will see an string on HTML SELECT, but code
    // returned on submit will not code for string you are seeing.!!!! 
    //
    // determine which filter method shall be selected by the JS function in template,
    // when only one build is selectable by the user
    $js_key_to_select = 0;
    if ($this->mode == 'execution_mode') 
    {
      $js_key_to_select = $this->configuration->filter_methods['status_code']['current_build'];
    } 
    else if ($this->mode == 'plan_mode') 
    {
      $js_key_to_select = $this->configuration->filter_methods['status_code']['specific_build'];
    }
    
    // values selected by user
    $result_selection = $this->args->$result_key;
    $method_selection = $this->args->$method_key;
    $build_selection = $this->args->$build_key;

    // default values
    $default_filter_method = $this->configuration->filter_methods['default_type'];
    $any_result_key = $this->configuration->results['status_code']['all'];
    $newest_build_id = $this->testplan_mgr->get_max_build_id($tplan_id, testplan::GET_ACTIVE_BUILD);

    if (is_null($method_selection)) 
    {
      $method_selection = $default_filter_method;
    }

    if (is_null($result_selection) || $this->args->reset_filters) 
    // if ($this->args->reset_filters)
    {
      // no selection yet or filter reset requested
      $result_selection = $any_result_key;
      $method_selection = $default_filter_method;
      $build_selection = $newest_build_id;
    } 
    else 
    {
      $this->do_filtering = true;
    }
    
    // init array structure
    $key = 'filter_result';
    $this->filters[$key] = array($result_key => array('items' => null,
                                                      'selected' => $result_selection),
                                 $method_key => array('items' => array(),
                                                      'selected' => $method_selection,
                                                      'js_selection' => $js_key_to_select),
                                 $build_key => array('items' => null,
                                                     'selected' => $build_selection));

    // init menu for result selection by function from exec.inc.php
    $this->filters[$key][$result_key]['items'] = createResultsMenu();
    $this->filters[$key][$result_key]['items'][$any_result_key] = $this->option_strings['any'];

    // init menu for filter method selection
    foreach ($this->configuration->filter_methods['status_code'] as $statusname => $statusshortcut) 
    {
      $code = $this->configuration->filter_methods['status_code'][$statusname];
      $this->filters[$key][$method_key]['items'][$code] =
        lang_get($this->configuration->filter_methods['status_label'][$statusname]);
    }
    
    // init menu for build selection
    $this->filters[$key][$build_key]['items'] =
      $this->testplan_mgr->get_builds_for_html_options($tplan_id, testplan::GET_ACTIVE_BUILD);
    
    // if "any" is selected, nullify the active filters
    if ((is_array($result_selection) && in_array($any_result_key, $result_selection)) || 
        $result_selection == $any_result_key) 
    {
      $this->active_filters[$result_key] = null;
      $this->active_filters[$method_key] = null;
      $this->active_filters[$build_key] = null;
      $this->filters[$key][$result_key]['selected'] = $any_result_key;
    } 
    else 
    {
      $this->active_filters[$result_key] = $result_selection;
      $this->active_filters[$method_key] = $method_selection;
      $this->active_filters[$build_key] = $build_selection;
    }
  } // end of method
  
  /**
   *
   */  
  private function init_filter_bugs() 
  {
    $key = str_replace('init_','',__FUNCTION__);
    $selection = $this->args->{$key};
    
    if (!$selection || $this->args->reset_filters) 
    {
      $selection = null;
    } 
    else 
    {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection);
    $this->active_filters[$key] = $selection;
  } 


  /**
   *
   *
   * @internal revisions
   * @since 1.9.14
   * allow multiple selection (if advanced mode)
   */
  private function init_filter_workflow_status() 
  {
    $key = 'filter_workflow_status';
    if (!$this->tc_mgr) 
    {
      $this->tc_mgr = new testcase($this->db);
    }

    // handle filter reset
    $cfx = $this->configuration->{$key . "_values"};
    $selection = $this->args->{$key};
    if (!$selection || $this->args->reset_filters) 
    {
      if( !is_null($this->args->caller) && !$selection)
      {
        $selection = null;
      }  
      else if( count($cfx) > 0)
      {
        $selection = $cfx;
        $this->do_filtering = true;
      }
      else
      {
        $selection = null;
      }  
    }  
    else 
    {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('items' => array(), 'selected' => $selection);

    // load domain
    // add "any" string to these types at index 0 as default selection
    $this->filters[$key]['items'] = array(0 => $this->option_strings['any']) +
                                          $this->tc_mgr->getWorkFlowStatusDomain();

    $this->filters[$key]['size'] = min(count($this->filters[$key]['items']),
                                       self::ADVANCED_FILTER_ITEM_QUANTITY);
    
    $this->active_filters[$key] = $selection;
  }


  
  /**
   *
   * @used-by __construct
   */
  private function initTreeOptions()
  {
    $this->treeOpt['plan_mode'] = new stdClass();
    $this->treeOpt['plan_mode']->useCounters = CREATE_TC_STATUS_COUNTERS_OFF;
    $this->treeOpt['plan_mode']->useColours = COLOR_BY_TC_STATUS_OFF;
    $this->treeOpt['plan_mode']->testcases_colouring_by_selected_build = DISABLED;
    $this->treeOpt['plan_mode']->absolute_last_execution = true;  // hmm  probably useless
    
  }

  /**
   *
   */
  protected function init_advanced_filter_mode() 
  {
    switch( $this->mode )
    {
      case 'edit_mode': 
        $this->advanced_filter_mode = TRUE;
      break;
 
      default:
        $m2c = __FUNCTION__;
        parent::$m2c();
      break;
    }
  } // end of method

  
  
  
  //---------------------
   /**
   *
   */
  private function init_filter_doc_id() 
  {
    $key = 'filter_doc_id';
    $selection = $this->args->{$key};
    
    if (!$selection || $this->args->reset_filters) 
    {
      $selection = null;
    } 
    else 
    {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection);
    $this->active_filters[$key] = $selection;
  } // end of method
  

  /**
  *
  */
  private function init_filter_title() 
  {
    $key = 'filter_title';
    $selection = $this->args->{$key};
    
    if (!$selection || $this->args->reset_filters) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection);
    $this->active_filters[$key] = $selection;
  } // end of method
  
  /*
  *
  */
  private function init_filter_status() {
    $key = 'filter_status';
    $selection = $this->args->{$key};
    
    // get configured statuses and add "any" string to menu
    $items = array(self::ANY => $this->option_strings['any']) + 
             (array) init_labels($this->configuration->req_cfg->status_labels);

    // BUGID 3852
    if (!$selection || $this->args->reset_filters
    || (is_array($selection) && in_array('0', $selection, true))) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection, 'items' => $items);
    $this->active_filters[$key] = $selection;
  } // end of method

  /**
   *
   */ 
  private function init_filter_type() {
    $key = 'filter_type';
    $selection = $this->args->{$key};
    
    // get configured types and add "any" string to menu
    $items = array(self::ANY => $this->option_strings['any']) + 
             (array) init_labels($this->configuration->req_cfg->type_labels);
  
    if (!$selection || $this->args->reset_filters
    || (is_array($selection) && in_array(self::ANY, $selection))) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection, 'items' => $items);
    $this->active_filters[$key] = $selection;
  } // end of method
  
  /**
   *
   */ 
  private function init_filter_spec_type() {
    $key = 'filter_spec_type';
    $selection = $this->args->{$key};
    
    // get configured types and add "any" string to menu
    $items = array(self::ANY => $this->option_strings['any']) + 
             (array) init_labels($this->configuration->req_spec_cfg->type_labels);
    
    if (!$selection || $this->args->reset_filters
    || (is_array($selection) && in_array(self::ANY, $selection))) {
      $selection = null;
    } else {
      $this->do_filtering = true;
    }
    
    $this->filters[$key] = array('selected' => $selection, 'items' => $items);
    $this->active_filters[$key] = $selection;
  } // end of method
  
  /**
   *
   */ 
  private function init_filter_coverage() {
    
    $key = 'filter_coverage';
    $this->filters[$key] = false;
    $this->active_filters[$key] = null;
    
    // is coverage management enabled?
    if ($this->configuration->req_cfg->expected_coverage_management) {
      $selection = $this->args->{$key};
    
      if (!$selection || !is_numeric($selection) || $this->args->reset_filters) {
        $selection = null;
      } else {
        $this->do_filtering = true;
      }
      
      $this->filters[$key] = array('selected' => $selection);
      $this->active_filters[$key] = $selection;
    }
  } // end of method
  
  /**
   *
   */ 
  private function init_filter_relation() {
    
    $key = 'filter_relation';
  
    // are relations enabled?
    if ($this->configuration->req_cfg->relations->enable) {
      $selection = $this->args->{$key};
      
      if (!$this->req_mgr) {
        $this->req_mgr = new requirement_mgr($this->db);
      }
      
      $req_relations = $this->req_mgr->init_relation_type_select();
      
      // special case here:
      // for equal type relations (where it doesn't matter if we find source or destination)
      // we have to remove the source identficator from the array key
      foreach ($req_relations['equal_relations'] as $array_key => $old_key) 
      {
        // set new key in array and delete old one
        $new_key = (int) str_replace("_source", "", $old_key);
        $req_relations['items'][$new_key] = $req_relations['items'][$old_key];
        unset($req_relations['items'][$old_key]);
      }
      
      $items = array(self::ANY => $this->option_strings['any']) + 
               (array) $req_relations['items'];

      if (!$selection || $this->args->reset_filters || 
          (is_array($selection) && in_array(self::ANY, $selection))) 
      {
        $selection = null;
      } 
      else 
      {
        $this->do_filtering = true;
      }
      
      $this->filters[$key] = array('selected' => $selection, 
                                   'items' => $items);
      $this->active_filters[$key] = $selection;
    } else {
      // not enabled, just nullify
      $this->filters[$key] = false;
      $this->active_filters[$key] = null;
    }   
  } // end of method

  
  /**
   *
   */ 
  protected function getCustomFields()
  {
    if (!$this->req_mgr) 
    {
      $this->req_mgr = new requirement_mgr($this->db);
      $this->cfield_mgr = &$this->req_mgr->cfield_mgr;
    }

    $cfields = $this->req_mgr->get_linked_cfields(null, null, $this->args->testproject_id);
    return $cfields;
  }

}