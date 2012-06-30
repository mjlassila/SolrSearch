<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * SolrSearch Omeka Plugin setup file.
 *
 * This file will set up the SolrSearch plugin.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not
 * use this file except in compliance with the License. You may obtain a copy of
 * the License at http://www.apache.org/licenses/LICENSE-2.0 Unless required by
 * applicable law or agreed to in writing, software distributed under the
 * License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS
 * OF ANY KIND, either express or implied. See the License for the specific
 * language governing permissions and limitations under the License.
 *
 * @package    omeka
 * @subpackage SolrSearch
 * @author     "Scholars Lab"
 * @copyright  2010 The Board and Visitors of the University of Virginia
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache 2.0
 * @version    $Id$
 * @link       http://www.scholarslab.org
 *
 * PHP version 5
 *
 */

// {{{ constants

if (!defined('SOLR_SEARCH_PLUGIN_VERSION')) {
    define('SOLR_SEARCH_PLUGIN_VERSION', get_plugin_ini('SolrSearch', 'version'));
}

if (!defined('SOLR_SERVER')) {
    define('SOLR_SERVER', get_option('solr_search_server'));
}

if (!defined('SOLR_PORT')) {
    define('SOLR_PORT', get_option('solr_search_port'));
}

if (!defined('SOLR_CORE')) {
    define('SOLR_CORE', get_option('solr_search_core'));
}

if (!defined('SOLR_ROWS')) {
    define('SOLR_ROWS', get_option('solr_search_rows'));
}

if (!defined('SOLR_FACET_LIMIT')) {
    define('SOLR_FACET_LIMIT', get_option('solr_search_facet_limit'));
}

// }}}

// Solr PHP Client library
require_once 'lib/Document.php';
require_once 'lib/Exception.php';
require_once 'lib/Response.php';
require_once 'lib/Service.php';

// SolrSearch utility classes
require_once 'lib/SolrSearch/QueryManager.php';

// {{{ pluginHooks
add_plugin_hook('install', 'solr_search_install');
add_plugin_hook('uninstall', 'solr_search_uninstall');
add_plugin_hook('before_delete_item', 'solr_search_before_delete_item');
add_plugin_hook('after_save_item', 'solr_search_after_save_item');
add_plugin_hook('define_routes', 'solr_search_define_routes');
add_plugin_hook('define_acl', 'solr_search_define_acl');
add_plugin_hook('admin_theme_header', 'solr_search_admin_header');
add_plugin_hook('public_theme_header', 'solr_search_public_header');
add_plugin_hook('config_form', 'solr_search_option_form');
add_plugin_hook('config', 'solr_search_config');
add_plugin_hook('initialize', 'solr_search_initialize');
//}}}

// {{{ filters
add_filter('admin_navigation_main', 'solr_search_admin_navigation');
// }}}

/**
 * Install the SolrSearch plugin; set up facet table and autopopulate from
 * items in the database.
 */
function solr_search_install()
{
    
	solrSearchCreateTable(); // create facet mapping table
	solrSearchAddFacetsMapping(); // populate the facets table
	
	// set solr options
	set_option('solr_search_server', 'localhost');
	set_option('solr_search_port', '8080');
	set_option('solr_search_core', '/solr/');
	set_option('solr_search_rows', '10');
	set_option('solr_search_facet_limit', '25');
	set_option('solr_search_hl', 'false');
	set_option('solr_search_snippets', '1');
	set_option('solr_search_fragsize', '100');
	set_option('solr_search_facet_sort', 'count');
	
	//add public items to Solr index - moved to config form submission
	//ProcessDispatcher::startProcess('SolrSearch_IndexAll', null, $args);
}

/**
 * Create the mapping table for human readable labels for Omeka elements
 */
function solrSearchCreateTable()
{
   	$db = get_db();
   	
   	$sql = <<<SQL
   	CREATE TABLE IF NOT EXISTS `{$db->prefix}solr_search_facets` (
        `id` int(10) unsigned NOT NULL auto_increment,
		`element_id` int(10) unsigned,
		`name` tinytext collate utf8_unicode_ci NOT NULL,	      
		`element_set_id` int(10) unsigned,
		`is_facet` tinyint unsigned DEFAULT 0,
		`is_displayed` tinyint unsigned DEFAULT 0,		
		`is_sortable` tinyint unsigned DEFAULT 0,
        PRIMARY KEY  (`id`)
       ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
SQL;

    $db->exec($sql);
}

/**
 * Populates the facet table with human readable mappings of Omeka Element ids
 *
 */
function solrSearchAddFacetsMapping()
{
    $db = get_db();
    
    // images are a very special case; faceting and sorting are not logical here
	$db->insert('solr_search_facets', array(
	    'name'=>'image', 
	    'is_displayed' => 1,
	    'is_facet' => NULL,
	    'is_sortable' => NULL
	    )
	);
    
    /*
     * special cases; default is to create facets, store, and sort <tt>tags</tt>,
     * <tt>collection</tt>, and <tt>itemtype</tt>
     */
    // tags
	$db->insert('solr_search_facets', array(
	    'name'=>'tag', 
	    'is_facet' => 1, 
	    'is_displayed' => 1,
	    'is_sortable' => 1
	    )
	); 
	// collection
	$db->insert('solr_search_facets', array(
	    'name'=>'collection',
	    'is_facet' => 1, 
	    'is_displayed' => 1,
	    'is_sortable' => 1
	    )
	); 
	
	// item type
	$db->insert('solr_search_facets', array(
	    'name'=>'itemtype',
	    'is_facet' => 1, 
	    'is_displayed' => 1,
	    'is_sortable' => 1
	    )
	); 
	
    // get the elements that are currently installed
    $elements = $db->getTable('Element')->findAll();
	
	// add all element names to facet table for selection
	foreach ($elements as $element){
		$data = array(
		    'element_id' => $element['id'],
            'name' => $element['name'],
            'element_set_id' => $element['element_set_id']
        );
        
		$db->insert('solr_search_facets', $data);
	}	
}

/** 
 * Uninstall SolrSearch plugin and cleanup database and index
 */
function solr_search_uninstall()
{
	// Drop the table.
	$db = get_db();
	$sql = "DROP TABLE IF EXISTS `{$db->prefix}solr_search_facets`";
	$db->query($sql);
	
	// delete Solr documents from index
	$solr = new Apache_Solr_Service(SOLR_SERVER, SOLR_PORT, SOLR_CORE);
	try {		
		$solr->deleteByQuery('*:*');
		$solr->commit();
		$solr->optimize(); 
	} catch ( Exception $err ) {
		echo $err->getMessage();
	}
	
	// delete solr options
	delete_option('solr_search_server');
	delete_option('solr_search_port');
	delete_option('solr_search_core');
	delete_option('solr_search_rows');
	delete_option('solr_search_facet_limit');
	delete_option('solr_search_hl');
	delete_option('solr_search_snippets');
	delete_option('solr_search_fragsize');
	delete_option('solr_search_facet_sort');
}

// delete an item from the index
function solr_search_before_delete_item($item)
{
	$solr = new Apache_Solr_Service(SOLR_SERVER, SOLR_PORT, SOLR_CORE);
	try {		
		$solr->deleteByQuery('id:' . $item['id']);
		$solr->commit();
		$solr->optimize(); 
	} catch ( Exception $err ) {
		echo $err->getMessage();
	}
}

// reindex an item
function solr_search_after_save_item($item)
{
	$solr = new Apache_Solr_Service(SOLR_SERVER, SOLR_PORT, SOLR_CORE);	
	//if item is public, save it to solr
	if ($item['public'] == '1'){		
		$db = get_db();
		$elementTexts = $db->getTable('ElementText')->findBySql('record_id = ?', array($item['id']));	
	
		$docs = array();
		
		$doc = new Apache_Solr_Document();
		$doc->id = $item['id'];
		foreach ($elementTexts as $elementText){
			$titleCount = 0;
			$fieldName = $elementText['element_id'] . '_s';
			$doc->setMultiValue($fieldName, $elementText['text']);
			//store Dublin Core titles as separate fields
			if ($elementText['element_id'] == 50){
				$doc->setMultiValue('title', $elementText['text']);
			} 
		}
		
		//add tags			
		foreach($item->Tags as $key => $tag){
			$doc->setMultiValue('tag', $tag);
		}
		
		//add collection
		if ($item['collection_id'] > 0){
			$collectionName = $db->getTable('Collection')->find($item['collection_id'])->name;
			$doc->collection = $collectionName;
		}
		
		//add item type
		if ($item['item_type_id'] > 0){
			$itemType = $db->getTable('ItemType')->find($item['item_type_id'])->name;
			$doc->itemtype = $itemType;
		}
		
		//add images or index XML files
		$files = $item->Files;
		foreach ($files as $file){
			$mimeType = $file->mime_browser;		
			if($file['has_derivative_image'] == 1){
				$doc->setMultiValue('image', $file['id']);
			}
			if ($mimeType == 'application/xml' || $mimeType == 'text/xml'){
				$teiFile = $file->getPath('archive');
				$xml_doc = new DomDocument;	
				$xml_doc->load($teiFile);
				$xpath = new DOMXPath($xml_doc);
				$nodes = $xpath->query('//text()');
				foreach ($nodes as $node){
					$value = preg_replace('/\s\s+/', ' ', trim($node->nodeValue));
					if ($value != ' ' && $value != ''){
						$doc->setMultiValue('fulltext', $value);
					}
				}
			}
		}
		
		//if FedoraConnector is installed, index fulltext of XML
		if (function_exists('fedora_connector_installed')){
			$datastreams = $db->getTable('FedoraConnector_Datastream')->findBySql('mime_type = ? AND item_id = ?', array('text/xml', $item->id));
			foreach($datastreams as $datastream){
				$teiFile = fedora_connector_content_url($datastream);
				$fedora_doc = new DomDocument;
				$fedora_doc->load($teiFile);
				$xpath = new DOMXPath($fedora_doc);
				$nodes = $xpath->query('//text()');
				foreach ($nodes as $node){
					$value = preg_replace('/\s\s+/', ' ', trim($node->nodeValue));
					if ($value != ' ' && $value != ''){
						$doc->setMultiValue('fulltext', $value);
					}
				}
			}
		}
		
		$docs[] = $doc;
		try {
	    	$solr->addDocuments($docs);
			$solr->commit();
			$solr->optimize();
		}
		catch ( Exception $err ) {
			echo $err->getMessage();
		}
	} else {
		//if item is no longer set as public, remove the item from index
		try {		
			$solr->deleteByQuery('id:' . $item['id']);
			$solr->commit();
			$solr->optimize(); 
		} catch ( Exception $err ) {
			echo $err->getMessage();
		}
	}
}

function xml_dom_iteration($child){
	$doc->setMultiValue('fulltext', $child);
	foreach($child->children() as $child){
		xml_dom_iteration($child);	
	}
}

/**
 * Define the routes.
 * 
 * @param Zend_Controller_Router_Rewrite
 */
function solr_search_define_routes($router)
{
	$searchResultsRoute = new Zend_Controller_Router_Route('results', 
                                                 array('controller' => 'search', 
                                                       'action'     => 'results', 
                                                       'module'     => 'solr-search'));
	$router->addRoute('solr_search_results_route', $searchResultsRoute);
}

/**
 * Navigation tab for admin panel if user has permission to configure SolrSearch
 *
 * @params $tabs 
 * @return $tabs 
 */
function solr_search_admin_navigation($tabs)
{
    if (get_acl()->checkUserPermission('SolrSearch_Config', 'index')) {
        $tabs['Solr Index'] = uri('solr-search/config/');        
    }
    return $tabs;
}
	
function solr_search_define_acl($acl)
{
    $acl->loadResourceList(array('SolrSearch_Config' => array('index', 'status')));
}

function solr_search_admin_header($request)
{
	if ($request->getModuleName() == 'solr-search') {
		echo '<link rel="stylesheet" href="' . html_escape(css('solr_search_main')) . '" />';
		//echo js('generic_xml_import_main');
    }
}

function solr_search_public_header($request)
{
	if ($request->getModuleName() == 'solr-search') {
		echo '<link rel="stylesheet" href="' . html_escape(css('solr_search_public')) . '" />';
		//echo js('generic_xml_import_main');
    }
}

//select fields to display in Solr search results
// function solr_search_config_form()
// {
//  $form = solr_search_options();
// echo '<style type="text/css">.zend_form>dd{ margin-bottom:20px; }</style>
//  <div class="field">
//      <h3>Solr Options</h3>
//      <p class="explanation">Set Solr options.</p>';
//     echo $form; 
//  echo '</div>';
// 
// }

// post displayble fields to index
function solr_search_config()
{
	
	$form = solr_search_options();
	
	if($form->isValid($_POST)) {
	    $options = $form->getValues();
	    
	    // set options
	    foreach($options as $option => $value) {
	        set_option($option, $value);
	    }
	    
	    // Now index
	    ProcessDispatcher::startProcess('SolrSearch_IndexAll', null, $args);
	} else {
	    echo '<div class="errors">';
	    
        var_dump($form->getMessages());
	    
	    echo '</div>';
	    break;
	}
  
}
// Prepare plugin for initialization
function solr_search_initialize()
{
    add_translation_source(dirname(__FILE__) . '/languages');
}

/*********
 * Displayable element form
 *********/
 
// TODO: migrate this in to ini file
function solr_search_option_form()
{
    include 'config_form.php';
}
 
function solr_search_options(){
    //require "Zend/Form/Element.php";
    
    $form = new Zend_Form();
    
    $solrServer = new Zend_Form_Element_Text ('solr_search_server');
    $solrServer->setLabel(__('Server:'));
    $solrServer->setValue(get_option('solr_search_server'));
    $solrServer->setRequired('true');
    $form->addElement($solrServer);
    
	$solrPort = new Zend_Form_Element_Text ('solr_search_port');
    $solrPort->setLabel(__('Port:'));
    $solrPort->setValue(get_option('solr_search_port'));
    $solrPort->setRequired('true');
    $solrPort->addValidator(new Zend_Validate_Digits());
    $form->addElement($solrPort);

	$solrCore = new Zend_Form_Element_Text ('solr_search_core');
    $solrCore->setLabel(__('Core:'));
    $solrCore->setValue(get_option('solr_search_core'));
    $solrCore->setRequired('true');    
    $solrCore->addValidator('regex', true, array('/\/.*\//i'));
    $form->addElement($solrCore);
    
    $solrRows = new Zend_Form_Element_Text ('solr_search_rows');
    $solrRows->setLabel(__('Results Per Page:'));
    $solrRows->setValue(get_option('solr_search_rows'));
    $solrRows->setRequired('true');
    $solrRows->addValidator(new Zend_Validate_Digits());
    $form->addElement($solrRows);
    
    $solrFacetSort = new Zend_Form_Element_Select ('solr_search_facet_sort');
    $solrFacetSort->setLabel(__('Facet Sort Order:'));
    $solrFacetSort->addMultiOption('index', 'Alphabetical');
	$solrFacetSort->addMultiOption('count', 'Occurrences');    
    $solrFacetSort->setValue(get_option('solr_search_facet_sort'));
    $form->addElement($solrFacetSort);
    
    $solrFacetLimit = new Zend_Form_Element_Text ('solr_search_facet_limit');
    $solrFacetLimit->setLabel(__('Maximum Facet Constraint Count:'));
    $solrFacetLimit->setValue(get_option('solr_search_facet_limit'));
    $solrFacetLimit->setRequired('true');
    $solrFacetLimit->addValidator(new Zend_Validate_Digits());
    $form->addElement($solrFacetLimit);
    
    return $form;
}

/*
 * Custom Theme Helpers. They're imported from the helpers file, which appears 
 * to get more love.
 */

require_once dirname(__FILE__) . '/helpers/SolrSearchFunctions.php';

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
