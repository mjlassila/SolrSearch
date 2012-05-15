<?php
/**
 * SolrSearch Omeka Plugin helpers.
 *
 * Default helpers for the SolrSearch plugin
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

/**
 * This handles indexes data from the addons.
 **/
class SolrSearch_Addon_Indexer
{
    //{{{ Properties

    /**
     * This is the database interface.
     *
     * @var Omeka_Db
     **/
    var $db;

    //}}}

    function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * This creates a Solr-style name for an addon and field.
     *
     * @param SolrSearch_Addon_Addon $addon This is the addon.
     * @param string                 $field The field to get.
     *
     * @return string $name The Solr name.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function makeSolrName($addon, $field)
    {
        return "{$addon->name}_{$field}_s";
    }

    /**
     * This gets all the records in the database matching all the addons passed 
     * in and returns a list of Solr documents for indexing.
     *
     * @param associative array of SolrSearch_Addon_Addon $addons The addon 
     * configuration information about the records to index.
     *
     * @return array of Apache_Solr_Document $docs The documents to index.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexAll($addons)
    {
        $docs = array();

        foreach ($addons as $name => $addon) {
            $docs = array_merge($docs, $this->indexAllAddon($addon));
        }

        return $docs;
    }

    /**
     * This gets all the records associated with a single addon for indexing.
     *
     * @param SolrSearch_Addon_Addon The addon to pull records for.
     *
     * @return array of Apache_Solr_Documents $docs The documents to index.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexAllAddon($addon)
    {
        $docs = array();

        $table  = $this->db->getTable($addon->table);
        $select = $this->buildSelect($table, $addon);
        $rows   = $table->fetchObjects($select);

        foreach ($rows as $record) {
            $doc = $this->indexRecord($record, $addon);
            $docs[] = $doc;
        }

        return $docs;
    }

    /**
     * This returns an Apache_Solr_Document to index, if the addons say it 
     * should be.
     *
     * @param Omeka_Record $record The record to index.
     * @param associative array of SolrSearch_Addon_Addon $addons The 
     * configuration controlling how records are indexed.
     *
     * @return Apache_Solr_Document|null
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexRecord($record, $addon)
    {
        $doc = new Apache_Solr_Document();

        $doc->id = "{$addon->table}_{$record->id}";
        $doc->addField('model', $addon->table);
        $doc->addField('modelid', $record->id);
        $doc->addField('url', SolrSearch_IndexHelpers::getUri($record));

        $titleField = $addon->getTitleField();
        foreach ($addon->fields as $field) {
            $solrName = $this->makeSolrName($addon, $field->name);

            if (is_null($field->remote)) {
                $value = $this->getLocalValue($record, $field);
            } else {
                $value = $this->getRemoteValue($record, $field);
            }

            foreach ($value as $v) {
                $doc->addField($solrName, $v);

                if (!is_null($titleField) && $titleField->name === $field->name) {
                    $doc->addField('title', $v);
                }
            }
        }

        if ($addon->tagged) {
            foreach ($record->getTags() as $tag) {
                $doc->addField('tag', $tag->name);
            }
        }

        if ($addon->resultType) {
            $doc->addField('resulttype', $addon->resultType);
        }

        return $doc;
    }

    /**
     * This returns a value that is local to the record.
     *
     * @param Omeka_Record           $record The record to get the value from.
     * @param SolrSearch_Addon_Field $field  The field that defines where to get
     * the value.
     *
     * @return mixed $value The value of the field in the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function getLocalValue($record, $field)
    {
        $value = array();
        $value[] = $record[$field->name];
        return $value;
    }

    /**
     * This returns a value that is remotely attached to the record.
     *
     * @param Omeka_Record           $record The record to get the value from.
     * @param SolrSearch_Addon_Field $field  The field that defines where to get
     * the value.
     *
     * @return mixed $value The value of the field in the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function getRemoteValue($record, $field)
    {
        $value = array();

        $table = $this->db->getTable($field->remote->table);
        $rows  = $table->findBy(array(
            $field->remote->key => $record->id
        ));

        foreach ($rows as $item) {
            $value[] = $item[$field->name];
        }

        return $value;
    }

    /**
     * This builds a query for returning all the records to index from the 
     * database.
     *
     * @param Omeka_Db_Table         $table The table to create the SQL for.
     * @param SolrSearch_Addon_Addon $addon The addon to generate SQL for.
     *
     * @return Omeka_Db_Select $select The select statement to execute for the 
     * query.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function buildSelect($table, $addon)
    {
        $select = $table
            ->select()
            ->from($table->getTableName());

        if ($addon->hasFlag()) {
            $this->_addFlag($select, $addon);
        }

        return $select;
    }

    /**
     * This adds the joins and where clauses to respect an addon's privacy 
     * settings.
     *
     * @param Omeka_Db_Select        $select The select object to modify.
     * @param SolrSearch_Addon_Addon $addon  The current addon. You should 
     * already know that this addon does have a public flag somewhere in its 
     * hierarchy before calling this.
     *
     * @return null
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    private function _addFlag($select, $addon)
    {
        if (!is_null($addon->flag)) {
            $table = $this->db->getTable($addon->table);
            $select->where(
                "`{$table->getTableName()}`.`{$addon->flag}`=1"
            );
        } else if (!is_null($addon->parentAddon)) {
            $parent = $addon->parentAddon;
            $table  = $this->db->getTable($addon->table)->getTableName();
            $ptable = $this->db->getTable($parent->table)->getTableName();

            $select->join(
                $ptable,
                "`$table`.`{$addon->parentKey}`=`$ptable`.`{$parent->idColumn}`",
                array()
            );

            $this->_addFlag($select, $parent);
        }
    }

    /**
     * This returns true if this addon (and none of its ancestors) are flagged.
     *
     * @param Omeka_Record           $record The Omeka record to consider 
     * indexing.
     * @param SolrSearch_Addon_Addon $addon  The addon for the record.
     *
     * @return bool $indexed A flag indicating whether to index the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function isRecordIndexed($record, $addon)
    {
        $indexed = true;

        if (is_null($record)) {

        } else if (!is_null($addon->flag)) {
            $flag = $addon->flag;
            $indexed = $record->$flag;

        } else if (!is_null($addon->parentAddon)) {
            $key    = $addon->parentKey;
            $table  = $this->db->getTable($addon->parentAddon->table);
            $parent = $table->find($record->$key);

            $indexed = $this->isRecordIndexed($parent, $addon->parentAddon);
        }

        return $indexed;
    }

}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
