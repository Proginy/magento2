<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Magento
 * @package     Magento_Core
 * @copyright   Copyright (c) 2013 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * Abstract Core Resource Collection
 *
 * @category    Magento
 * @package     Magento_Core
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Core\Model\Resource\Db\Collection;

abstract class AbstractCollection extends \Magento\Data\Collection\Db
{
    /**
     * Model name
     *
     * @var string
     */
    protected $_model;

    /**
     * Resource model name
     *
     * @var string
     */
    protected $_resourceModel;

    /**
     * Resource instance
     *
     * @var \Magento\Core\Model\Resource\Db\AbstractDb
     */
    protected $_resource;

    /**
     * Fields to select in query
     *
     * @var array|null
     */
    protected $_fieldsToSelect         = null;

    /**
     * Fields initial fields to select like id_field
     *
     * @var array|null
     */
    protected $_initialFieldsToSelect  = null;

    /**
     * Fields to select changed flag
     *
     * @var boolean
     */
    protected $_fieldsToSelectChanged  = false;

    /**
     * Store joined tables here
     *
     * @var array
     */
    protected $_joinedTables           = array();

    /**
     * Collection main table
     *
     * @var string
     */
    protected $_mainTable              = null;

    /**
     * Reset items data changed flag
     *
     * @var boolean
     */
    protected $_resetItemsDataChanged   = false;

    /**
     * Name prefix of events that are dispatched by model
     *
     * @var string
     */
    protected $_eventPrefix = '';

    /**
     * Name of event parameter
     *
     * @var string
     */
    protected $_eventObject = '';

    /**
     * Core event manager proxy
     *
     * @var \Magento\Event\ManagerInterface
     */
    protected $_eventManager = null;

    /**
     * @param \Magento\Event\ManagerInterface $eventManager
     * @param \Magento\Core\Model\Logger $logger
     * @param \Magento\Data\Collection\Db\FetchStrategyInterface $fetchStrategy
     * @param \Magento\Core\Model\EntityFactory $entityFactory
     * @param \Magento\Core\Model\Resource\Db\AbstractDb $resource
     */
    public function __construct(
        \Magento\Event\ManagerInterface $eventManager,
        \Magento\Core\Model\Logger $logger,
        \Magento\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Core\Model\EntityFactory $entityFactory,
        \Magento\Core\Model\Resource\Db\AbstractDb $resource = null
    ) {
        $this->_eventManager = $eventManager;
        parent::__construct($logger, $fetchStrategy, $entityFactory);
        $this->_construct();
        $this->_resource = $resource;
        $this->setConnection($this->getResource()->getReadConnection());
        $this->_initSelect();
    }

    /**
     * Initialization here
     *
     */
    protected function _construct()
    {

    }

    /**
     * Retrieve main table
     *
     * @return string
     */
    public function getMainTable()
    {
        if ($this->_mainTable === null) {
            $this->setMainTable($this->getResource()->getMainTable());
        }

        return $this->_mainTable;
    }

    /**
     * Set main collection table
     *
     * @param string $table
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function setMainTable($table)
    {
        $table = $this->getTable($table);
        if ($this->_mainTable !== null && $table !== $this->_mainTable && $this->getSelect() !== null) {
            $from = $this->getSelect()->getPart(\Zend_Db_Select::FROM);
            if (isset($from['main_table'])) {
                $from['main_table']['tableName'] = $table;
            }
            $this->getSelect()->setPart(\Zend_Db_Select::FROM, $from);
        }

        $this->_mainTable = $table;
        return $this;
    }

    /**
     * Init collection select
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _initSelect()
    {
        $this->getSelect()->from(array('main_table' => $this->getMainTable()));
        return $this;
    }

    /**
     * Get \Zend_Db_Select instance and applies fields to select if needed
     *
     * @return \Magento\DB\Select
     */
    public function getSelect()
    {
        if ($this->_select && $this->_fieldsToSelectChanged) {
            $this->_fieldsToSelectChanged = false;
            $this->_initSelectFields();
        }
        return parent::getSelect();
    }

    /**
     * Init fields for select
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _initSelectFields()
    {
        $columns = $this->_select->getPart(\Zend_Db_Select::COLUMNS);
        $columnsToSelect = array();
        foreach ($columns as $columnEntry) {
            list($correlationName, $column, $alias) = $columnEntry;
            if ($correlationName !== 'main_table') { // Add joined fields to select
                if ($column instanceof \Zend_Db_Expr) {
                    $column = $column->__toString();
                }
                $key = ($alias !== null ? $alias : $column);
                $columnsToSelect[$key] = $columnEntry;
            }
        }

        $columns = $columnsToSelect;

        $columnsToSelect = array_keys($columnsToSelect);

        if ($this->_fieldsToSelect !== null) {
            $insertIndex = 0;
            foreach ($this->_fieldsToSelect as $alias => $field) {
                if (!is_string($alias)) {
                    $alias = null;
                }

                if ($field instanceof \Zend_Db_Expr) {
                    $column = $field->__toString();
                } else {
                    $column = $field;
                }

                if (($alias !== null && in_array($alias, $columnsToSelect)) ||
                    // If field already joined from another table
                    ($alias === null && isset($alias, $columnsToSelect))) {
                    continue;
                }

                $columnEntry = array('main_table', $field, $alias);
                array_splice($columns, $insertIndex, 0, array($columnEntry)); // Insert column
                $insertIndex ++;

            }
        } else {
            array_unshift($columns, array('main_table', '*', null));
        }

        $this->_select->setPart(\Zend_Db_Select::COLUMNS, $columns);

        return $this;
    }

    /**
     * Retrieve initial fields to select like id field
     *
     * @return array
     */
    protected function _getInitialFieldsToSelect()
    {
        if ($this->_initialFieldsToSelect === null) {
            $this->_initialFieldsToSelect = array();
            $this->_initInitialFieldsToSelect();
        }

        return $this->_initialFieldsToSelect;
    }

    /**
     * Initialize initial fields to select like id field
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _initInitialFieldsToSelect()
    {
        $idFieldName = $this->getResource()->getIdFieldName();
        if ($idFieldName) {
            $this->_initialFieldsToSelect[] = $idFieldName;
        }
        return $this;
    }

    /**
     * Add field to select
     *
     * @param string|array $field
     * @param string|null $alias
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function addFieldToSelect($field, $alias = null)
    {
        if ($field === '*') { // If we will select all fields
            $this->_fieldsToSelect = null;
            $this->_fieldsToSelectChanged = true;
            return $this;
        }

        if (is_array($field)) {
            if ($this->_fieldsToSelect === null) {
                $this->_fieldsToSelect = $this->_getInitialFieldsToSelect();
            }

            foreach ($field as $key => $value) {
                $this->addFieldToSelect(
                    $value,
                    (is_string($key) ? $key : null),
                    false
                );
            }

            $this->_fieldsToSelectChanged = true;
            return $this;
        }

        if ($alias === null) {
            $this->_fieldsToSelect[] = $field;
        } else {
            $this->_fieldsToSelect[$alias] = $field;
        }

        $this->_fieldsToSelectChanged = true;
        return $this;
    }

    /**
     * Add attribute expression (SUM, COUNT, etc)
     * Example: ('sub_total', 'SUM({{attribute}})', 'revenue')
     * Example: ('sub_total', 'SUM({{revenue}})', 'revenue')
     * For some functions like SUM use groupByAttribute.
     *
     * @param string $alias
     * @param string $expression
     * @param array|string $fields
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function addExpressionFieldToSelect($alias, $expression, $fields)
    {
        // validate alias
        if (!is_array($fields)) {
            $fields = array($fields => $fields);
        }

        $fullExpression = $expression;
        foreach ($fields as $fieldKey=>$fieldItem) {
            $fullExpression = str_replace('{{' . $fieldKey . '}}', $fieldItem, $fullExpression);
        }

        $this->getSelect()->columns(array($alias=>$fullExpression));

        return $this;
    }

    /**
     * Removes field from select
     *
     * @param string|null $field
     * @param boolean $isAlias Alias identifier
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function removeFieldFromSelect($field, $isAlias = false)
    {
        if ($isAlias) {
            if (isset($this->_fieldsToSelect[$field])) {
                unset($this->_fieldsToSelect[$field]);
            }
        } else {
            foreach ($this->_fieldsToSelect as $key => $value) {
                if ($value === $field) {
                    unset($this->_fieldsToSelect[$key]);
                    break;
                }
            }
        }

        $this->_fieldsToSelectChanged = true;
        return $this;
    }

    /**
     * Removes all fields from select
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function removeAllFieldsFromSelect()
    {
        $this->_fieldsToSelect = $this->_getInitialFieldsToSelect();
        $this->_fieldsToSelectChanged = true;
        return $this;
    }

    /**
     * Standard resource collection initialization
     *
     * @param string $model
     * @param string $resourceModel
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _init($model, $resourceModel)
    {
        $this->setModel($model);
        $this->setResourceModel($resourceModel);
        return $this;
    }

    /**
     * Set model name for collection items
     *
     * @param string $model
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function setModel($model)
    {
        if (is_string($model)) {
            $this->_model = $model;
            $this->setItemObjectClass($model);
        }
        return $this;
    }

    /**
     * Get model instance
     *
     * @param array $args
     * @return \Magento\Object
     */
    public function getModelName($args = array())
    {
        return $this->_model;
    }

    /**
     * Set resource model name for collection items
     *
     * @param string $model
     */
    public function setResourceModel($model)
    {
        $this->_resourceModel = $model;
    }

    /**
     *  Retrieve resource model name
     *
     * @return string
     */
    public function getResourceModelName()
    {
        return $this->_resourceModel;
    }

    /**
     * Get resource instance
     *
     * @return \Magento\Core\Model\Resource\Db\AbstractDb
     */
    public function getResource()
    {
        if (empty($this->_resource)) {
            $this->_resource = \Magento\Core\Model\ObjectManager::getInstance()->create($this->getResourceModelName());
        }
        return $this->_resource;
    }

    /**
     * Retrieve table name
     *
     * @param string $table
     * @return string
     */
    public function getTable($table)
    {
        return $this->getResource()->getTable($table);
    }

    /**
     * Retrieve all ids for collection
     *
     * @return array
     */
    public function getAllIds()
    {
        $idsSelect = clone $this->getSelect();
        $idsSelect->reset(\Zend_Db_Select::ORDER);
        $idsSelect->reset(\Zend_Db_Select::LIMIT_COUNT);
        $idsSelect->reset(\Zend_Db_Select::LIMIT_OFFSET);
        $idsSelect->reset(\Zend_Db_Select::COLUMNS);

        $idsSelect->columns($this->getResource()->getIdFieldName(), 'main_table');
        return $this->getConnection()->fetchCol($idsSelect, $this->_bindParams);
    }

    /**
     * Join table to collection select
     *
     * @param string $table
     * @param string $cond
     * @param string $cols
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function join($table, $cond, $cols = '*')
    {
        if (is_array($table)) {
            foreach ($table as $k => $v) {
                $alias = $k;
                $table = $v;
                break;
            }
        } else {
            $alias = $table;
        }

        if (!isset($this->_joinedTables[$table])) {
            $this->getSelect()->join(
                array($alias => $this->getTable($table)),
                $cond,
                $cols
            );
            $this->_joinedTables[$alias] = true;
        }
        return $this;
    }

    /**
     * Redeclare before load method for adding event
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _beforeLoad()
    {
        parent::_beforeLoad();
        $this->_eventManager->dispatch('core_collection_abstract_load_before', array('collection' => $this));
        if ($this->_eventPrefix && $this->_eventObject) {
            $this->_eventManager->dispatch($this->_eventPrefix.'_load_before', array(
                $this->_eventObject => $this
            ));
        }
        return $this;
    }

    /**
     * Set reset items data changed flag
     *
     * @param boolean $flag
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function setResetItemsDataChanged($flag)
    {
        $this->_resetItemsDataChanged = (bool)$flag;
        return $this;
    }

    /**
     * Set flag data has changed to all collection items
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function resetItemsDataChanged()
    {
        foreach ($this->_items as $item) {
            $item->setDataChanges(false);
        }

        return $this;
    }

    /**
     * Redeclare after load method for specifying collection items original data
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            $item->setOrigData();
            if ($this->_resetItemsDataChanged) {
                $item->setDataChanges(false);
            }
        }
        $this->_eventManager->dispatch('core_collection_abstract_load_after', array('collection' => $this));
        if ($this->_eventPrefix && $this->_eventObject) {
            $this->_eventManager->dispatch($this->_eventPrefix.'_load_after', array(
                $this->_eventObject => $this
            ));
        }
        return $this;
    }

    /**
     * Save all the entities in the collection
     *
     * @return \Magento\Core\Model\Resource\Db\Collection\AbstractCollection
     */
    public function save()
    {
        foreach ($this->getItems() as $item) {
            $item->save();
        }
        return $this;
    }

    /**
     * Format Date to internal database date format
     *
     * @param int|string|Zend_Date $date
     * @param boolean $includeTime
     * @return string
     */
    public function formatDate($date, $includeTime = true)
    {
        return \Magento\Date::formatDate($date, $includeTime);
    }
}
