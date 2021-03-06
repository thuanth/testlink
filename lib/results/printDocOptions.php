<?php
/** 
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later.
 *  
 * @filesource printDocOptions.php
 * 
 *  Settings for generated documents
 *  - Structure of a document 
 *  - It builds the javascript tree that allow the user select a required part 
 *    Test specification/ Test plan.
 *
 *
 */
require_once("../../config.inc.php");
require_once("../../cfg/reports.cfg.php");
require_once("common.php");
require_once("treeMenu.inc.php");

testlinkInitPage($db);
$templateCfg = templateConfiguration();

$tprojMgr = new testproject($db);
$args = init_args($db,$tprojMgr);
$gui = initializeGui($db,$args,$tprojMgr);
$rightPaneAction = 'lib/results/printDocument.php';
$additionalArgs = '';

switch($args->doc_type)  {
  case DOC_TEST_SPEC:
  case DOC_REQ_SPEC:
    $gui->buildInfoSet = null;
  break;

  case DOC_TEST_PLAN_DESIGN:
  case DOC_TEST_PLAN_EXECUTION:
  case DOC_TEST_PLAN_EXECUTION_ON_BUILD:
    $tplan_mgr = new testplan($db);
    $tplan_info = $tplan_mgr->get_by_id($args->tplan_id);
    $testplan_name = htmlspecialchars($tplan_info['name']);

    // 20131201 - do we really need this ?
    // $filters = new stdClass();
    // $filters->build_id = $tplan_mgr->get_max_build_id($args->tplan_id);
    $gui->buildInfoSet = null;
    if( $args->doc_type == DOC_TEST_PLAN_EXECUTION_ON_BUILD) {
      $gui->buildInfoSet = $tplan_mgr->get_builds($args->tplan_id);

      if( null != $gui->buildInfoSet ) {
        $gui->buildRptLinkSet = array();
        $dl = $args->basehref .
              "lnl.php?apikey=" . $args->tplan_info['api_key'] .
              "&tproject_id=$args->tproject_id" .
              "&tplan_id=$args->tplan_id" .
              "&type=testreport_onbuild";

        foreach( $gui->buildInfoSet as $bid => $nunu ) {
          $gui->buildRptLinkSet[$bid] = $dl . "&build_id=$bid";         
        }       
      }
    } 

    $additionalInfo = new stdClass();
    $additionalInfo->useCounters = CREATE_TC_STATUS_COUNTERS_OFF;
    $additionalInfo->useColours = COLOR_BY_TC_STATUS_OFF;
     
    $opt_etree = new stdClass();
    $opt_etree->tc_action_enabled = 0;
    $opt_etree->allow_empty_build = 1;
    $opt_etree->getTreeMethod = 'getLinkedForTesterAssignmentTree';
    $opt_etree->useCounters = CREATE_TC_STATUS_COUNTERS_OFF;
    
    $opt_etree->useColours = new stdClass();
    $opt_etree->useColours->testcases = COLOR_BY_TC_STATUS_OFF;
    $opt_etree->useColours->counters =  COLOR_BY_TC_STATUS_OFF;

    switch($args->activity) {
      case 'addTC':
        $opt_etree->hideTestCases = SHOW_TESTCASES;
        $opt_etree->tc_action_enabled = false;
        $opt_etree->showTestCaseExecStatus = false;
        $opt_etree->nodeHelpText = array();
        $opt_etree->nodeHelpText['testproject'] = lang_get('gen_test_plan_design_report');
        $opt_etree->nodeHelpText['testsuite'] = $opt_etree->nodeHelpText['testproject'];                                                  
        
        $opt_etree->actionJS['testproject'] = 'TPLAN_PTP';
        $opt_etree->actionJS['testsuite'] = 'TPLAN_PTS';
      break;

      default:
        $opt_etree->hideTestCases = HIDE_TESTCASES;
      break;
    }

    $filters = null;
    $treeContents = null;
    list($treeContents, $testcases_to_show) = 
      testPlanTree($db,$rightPaneAction,$args->tproject_id,
                   $args->tproject_name,$args->tplan_id,
                   $testplan_name,$filters,$opt_etree);

   
    $gui->ajaxTree = new stdClass();
    $gui->ajaxTree->cookiePrefix = "{$args->doc_type}_tplan_id_{$args->tplan_id}_";
    $gui->ajaxTree->loadFromChildren = true;
    $gui->ajaxTree->root_node = $treeContents->rootnode;
    $gui->ajaxTree->children = trim($treeContents->menustring);

    if($gui->ajaxTree->children == ''){
      $gui->ajaxTree->children = '{}';  // generate valid JSON
      $gui->ajaxTree->root_node->href = '';
    }  
  break;

  default:
    tLog("Argument _REQUEST['type'] has invalid value", 'ERROR');
    exit();
  break;
}

$smarty = new TLSmarty();
$smarty->assign('gui', $gui);
$smarty->assign('args', $gui->getArguments);
$smarty->assign('selFormat', $args->format);
$smarty->assign('docType', $args->doc_type);
$smarty->assign('docTestPlanId', $args->tplan_id);
$smarty->assign('menuUrl', $rightPaneAction);
$smarty->assign('additionalArgs',$additionalArgs);

$optCfg = new printDocOptions();
$smarty->assign('printPreferences', $optCfg->getJSPrintPreferences());

$smarty->display($templateCfg->tpl);


/**
 * get user input and create an object with properties representing this inputs.
 * @return stdClass object 
 */
function init_args(&$dbHandler,&$tprojMgr) {
  $args = new stdClass();
  $iParams = array("tplan_id" => array(tlInputParameter::INT_N),
                   "tproject_id" => array(tlInputParameter::INT_N),
                   "format" => array(tlInputParameter::INT_N,999),
                   "type" => array(tlInputParameter::STRING_N,0,100),
                   "activity" => array(tlInputParameter::STRING_N,1,10),
                   "warning" => array(tlInputParameter::STRING_N,1,12));  
  
  $l18n = array();
  $l18n['addTC'] = lang_get('navigator_add_remove_tcase_to_tplan');
  $l18n['test_plan'] = lang_get('test_plan');


  R_PARAMS($iParams,$args);

  $args->drawTree = true;
  if ($args->warning != '') {
    $args->drawTree = false;
    $args->warning = lang_get($args->warning);
  }

  if ($args->tplan_id > 0) {  
    $tplan_mgr = new testplan($dbHandler);
    $args->tplan_info = $tplan_mgr->get_by_id($args->tplan_id);
    $args->mainTitle = $l18n['test_plan'] . ': ' . $args->tplan_info['name'];
  }

  if ($args->tproject_id == 0 && $args->tplan_id >0) {
    $args->tproject_id = $args->tplan_info['testproject_id'];
  }
  $args->tproject_name = testproject::getName($dbHandler,$args->tproject_id);

  $args->basehref = $_SESSION['basehref'];
  
  $tprjOpt = $tprojMgr->getOptions($args->tproject_id);
  $args->testprojectOptReqs = $tprjOpt->requirementsEnabled;
 
  $args->format = is_null($args->format) ? FORMAT_HTML : $args->format;
  $args->type = is_null($args->type) ? DOC_TEST_PLAN_DESIGN : $args->type;
  $args->doc_type = $args->type;

  // Changes to call this page also in add/remove test cases feature  
  $args->showOptions = true;
  $args->showHelpIcon = true;
  $args->tplan_info = null;
  $args->mainTitle = '';


  if ($args->activity != '') {
    $args->showOptions = false;
    $args->showHelpIcon = false;
  }  

  
  return $args;
}


/**
 * Initialize gui (stdClass) object that will be used as argument
 * in call to Template Engine.
 *
 * @param class pointer args: object containing User Input and some session values
 *    TBD structure
 * 
 * ?     tprojectMgr: test project manager object.
 * ?     treeDragDropEnabled: true/false. Controls Tree drag and drop behaivor.
 * 
 * @return stdClass TBD structure
 */ 
function initializeGui(&$db,&$args,&$tprojectMgr) {
  $tcaseCfg = config_get('testcase_cfg');
  $reqCfg = config_get('req_cfg');
        
  $gui = new stdClass();
  $gui->drawTree = $args->drawTree;
  $gui->warning = $args->warning;
  
  $gui->showOptionsCheckBoxes = $gui->showOptions = $args->showOptions;

  $gui->showHelpIcon = $args->showHelpIcon;

  $gui->mainTitle = '';
  $gui->outputFormat = array(FORMAT_HTML => lang_get('format_html'), 
                             FORMAT_MSWORD => lang_get('format_pseudo_msword'));

  $gui->outputOptions = init_checkboxes($args);
  if($gui->showOptions == false) {
    $loop2do = count($gui->outputOptions);
    for($idx = 0; $idx < $loop2do; $idx++) {
      $gui->outputOptions[$idx]['checked'] = 'y';
    }  
  }  

  $tcasePrefix = $tprojectMgr->getTestCasePrefix($args->tproject_id);

  $gui->tree_title = '';
  $gui->ajaxTree = new stdClass();
  $gui->ajaxTree->root_node = new stdClass();
  $gui->ajaxTree->dragDrop = new stdClass();
  $gui->ajaxTree->dragDrop->enabled = false;
  $gui->ajaxTree->dragDrop->BackEndUrl = null;
  $gui->ajaxTree->children = '';
     
  // improved cookie prefix for test spec doc and req spec doc
  $gui->ajaxTree->cookiePrefix = $args->doc_type . '_doc_';
  $gui->doc_type = $args->doc_type;
    
  $addTestPlanID = false;
  switch($args->doc_type) {
    case DOC_REQ_SPEC:
      $gui->showOptions = true;
      $gui->showOptionsCheckBoxes = false;
      $gui->tree_title = lang_get('title_req_print_navigator');
      $gui->ajaxTree->loader = $args->basehref . 'lib/ajax/getrequirementnodes.php?' .
                               "root_node={$args->tproject_id}&show_children=0&operation=print";
          
      $gui->ajaxTree->loadFromChildren = 0;
      $gui->ajaxTree->root_node->href = "javascript:TPROJECT_PTP_RS({$args->tproject_id})";
      $gui->ajaxTree->root_node->id = $args->tproject_id;

      $req_qty = $tprojectMgr->count_all_requirements($args->tproject_id);
      $gui->ajaxTree->root_node->name = htmlspecialchars($args->tproject_name) . " ($req_qty)";
      $gui->ajaxTree->cookiePrefix .= "tproject_id_" . $gui->ajaxTree->root_node->id . "_" ;
      $gui->mainTitle = lang_get('requirement_specification_report');
    break;
      
    case DOC_TEST_SPEC:
      $gui->tree_title = lang_get('title_tc_print_navigator');
      $gui->ajaxTree->loader = $args->basehref . 'lib/ajax/gettprojectnodes.php?' .
                               "root_node={$args->tproject_id}&" .
                               "show_tcases=0&operation=print&" .
                               "tcprefix=". urlencode($tcasePrefix.$tcaseCfg->glue_character) ."}";
            
      $gui->ajaxTree->loadFromChildren = 0;
      $gui->ajaxTree->root_node->href = "javascript:TPROJECT_PTP({$args->tproject_id})";
      $gui->ajaxTree->root_node->id = $args->tproject_id;

      $tcase_qty = $tprojectMgr->count_testcases($args->tproject_id);
      $gui->ajaxTree->root_node->name = htmlspecialchars($args->tproject_name) . " ($tcase_qty)";
      $gui->ajaxTree->cookiePrefix .= "tproject_id_" . $gui->ajaxTree->root_node->id . "_" ;
      $gui->mainTitle = lang_get('testspecification_report');
    break;
      
    case DOC_TEST_PLAN_EXECUTION:
      $addTestPlanID = true;
      $gui->mainTitle = lang_get('test_report');
    break;
        
    case DOC_TEST_PLAN_DESIGN:
      $addTestPlanID = true;
      $gui->tree_title = lang_get('title_tp_print_navigator');
      $gui->ajaxTree->loadFromChildren = 1;
      $gui->ajaxTree->loader = '';
      $gui->mainTitle = lang_get('report_test_plan_design');
    break;

    case DOC_TEST_PLAN_EXECUTION_ON_BUILD:
      $addTestPlanID = true;
      $gui->mainTitle = lang_get('test_report_on_build');
    break;

  }

  // Do not move
  if($args->mainTitle == '') {  
    $gui->mainTitle .=  ' - ' . lang_get('doc_opt_title');
  } else {
    $gui->mainTitle = $args->mainTitle; 
  } 

  switch ($args->activity) {
    case 'addTC':
      $gui->mainTitle = lang_get('testplan_design'); 
    break;
  }

  $gui->getArguments = "&type=" . $args->doc_type; 
  if ($addTestPlanID) {
    $gui->getArguments .= '&docTestPlanId=' . $args->tplan_id;
  }
  return $gui;  
}

/**
 * Initializes the checkbox options.
 * Made this a function to simplify handling of differences 
 * between printing for requirements and testcases and to make code more readable.
 * 
 * ATTENTION if you add somethin here, you need also to work on javascript function
 * tree_getPrintPreferences()
 *
 * @author Andreas Simon
 * 
 * @param stdClass $args reference to user input parameters
 * 
 * @return array $cbSet
 */
function init_checkboxes(&$args) {
  // Important Notice:
  // If you want to add or remove elements in this array, you must also update
  // $printingOptions in printDocument.php and tree_getPrintPreferences() in testlink_library.js
  
  $execCfg = config_get('exec_cfg');

  $optCfg = new printDocOptions();

  // Check Box Set
  $cbSet = array();
  
  $cbSet += $optCfg->getDocOpt();
  switch($args->doc_type) {
    case 'reqspec':
      $cbSet = array_merge($cbSet,$optCfg->getReqSpecOpt());
    break;

    default:
      $cbSet = array_merge($cbSet,$optCfg->getTestSpecOpt());
    break;
  }

  if( $args->doc_type == DOC_TEST_PLAN_EXECUTION || 
      $args->doc_type == DOC_TEST_PLAN_EXECUTION_ON_BUILD ) {
    $cbSet = array_merge($cbSet,$optCfg->getExecOpt());
  }  

  foreach ($cbSet as $key => $elem)  {
    $cbSet[$key]['description'] = lang_get($elem['description']);
    if( !isset($cbSet[$key]['checked']) ) {
      $cbSet[$key]['checked'] = 'n'; 
    }
  }

  return $cbSet;
}
