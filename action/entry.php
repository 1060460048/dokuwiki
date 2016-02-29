<?php
/**
 * DokuWiki Plugin struct (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

use plugin\struct\meta\Assignments;
use plugin\struct\meta\SchemaData;
use plugin\struct\meta\ValidationException;
use plugin\struct\types\AbstractBaseType;

/**
 * Class action_plugin_struct_entry
 *
 * Handles the whole struct data entry process
 */
class action_plugin_struct_entry extends DokuWiki_Action_Plugin {

    /**
     * @var string The form name we use to transfer schema data
     */
    protected static $VAR = 'struct_schema_data';

    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /** @var  bool has the data been validated correctly? */
    protected $validated;

    /** @var  array these schemas have changed data and need to be saved */
    protected $tosave;

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        // add the struct editor to the edit form;
        $controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE', $this, 'handle_editform');
        // validate data on preview and save;
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_validation');
        // ensure a page revision is created when struct data changes:
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handle_pagesave_before');
        // save struct data after page has been saved:
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
    }

    /**
     * Enhance the editing form with structural data editing
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return bool
     */
    public function handle_editform(Doku_Event $event, $param) {
        global $ID;

        $assignments = new Assignments();
        $tables = $assignments->getPageAssignments($ID);

        $html = '';
        foreach($tables as $table) {
            $html .= $this->createForm($table);
        }

        /** @var Doku_Form $form */
        $form = $event->data;
        $html = "<div class=\"struct\">$html</div>";
        $pos = $form->findElementById('wiki__editbar'); // insert the form before the main buttons
        $form->insertElement($pos, $html);

        return true;
    }

    /**
     * Clean up and validate the input data
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return bool
     */
    public function handle_validation(Doku_Event $event, $param) {
        global $ID, $INPUT;
        $act = act_clean($event->data);
        if(!in_array($act, array('save', 'preview'))) return false;

        $assignments = new Assignments();
        $tables = $assignments->getPageAssignments($ID);
        $structData = $INPUT->arr(self::$VAR);
        $timestamp = time();

        $this->tosave = array();
        $this->validated = true;
        foreach($tables as $table) {
            $schemaData = new SchemaData($table, $ID, $timestamp);
            if(!$schemaData->getId()) {
                // this schema is not available for some reason. skip it
                continue;
            }

            $newData = $structData[$table];
            foreach($schemaData->getColumns() as $col) {
                // fix multi value types
                $type = $col->getType();
                $label = $type->getLabel();
                $trans = $type->getTranslatedLabel();
                if($type->isMulti() && !is_array($newData[$label])) {
                    $newData[$label] = $type->splitValues($newData[$label]);
                }
                // strip empty fields from multi vals
                if(is_array($newData[$label])) {
                    $newData[$label] = array_filter($newData[$label], array($this, 'filter'));
                    $newData[$label] = array_values($newData[$label]); // reset the array keys
                }

                // validate data
                $this->validated = $this->validated && $this->validate($type, $trans, $newData[$label]);
            }

            // has the data changed? mark it for saving.
            $olddata = $schemaData->getDataArray();
            if($olddata != $newData) {
                $this->tosave[] = $table;
            }

            // write back cleaned up data
            $structData[$table] = $newData;
        }
        // write back cleaned up structData
        $INPUT->post->set(self::$VAR, $structData);

        // did validation go through? otherwise abort saving
        if(!$this->validated && $act == 'save') {
            $event->data = 'edit';
        }

        return false;
    }

    /**
     * Check if the page has to be changed
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return bool
     */
    public function handle_pagesave_before(Doku_Event $event, $param) {
        if($event->data['contentChanged']) return; // will be saved for page changes
        if(count($this->tosave)) {
            if(trim($event->data['newContent']) === '') {
                // this happens when a new page is tried to be created with only struct data
                msg($this->getLang('emptypage'), -1);
            } else {
                $event->data['contentChanged'] = true; // save for data changes
            }
        }
    }

    /**
     * Save the data
     *
     * When this is called, INPUT data has been validated already. On a restore action, the data is
     * loaded from the database and not validated again.
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return bool
     */
    public function handle_pagesave_after(Doku_Event $event, $param) {
        global $INPUT;
        global $ACT;
        global $REV;

        $assignments = new Assignments();

        if($ACT == 'revert' && $REV) {
            // reversion is a special case, we load the data to restore from DB:
            $structData = array();
            $this->tosave = $assignments->getPageAssignments($event->data['id']);
            foreach($this->tosave as $table) {
                $oldData = new SchemaData($table, $event->data['id'], $REV);
                $structData[$table] = $oldData->getDataArray();
            }
        } else {
            // data comes from the edit form
            $structData = $INPUT->arr(self::$VAR);
        }

        if($event->data['changeType'] == DOKU_CHANGE_TYPE_DELETE) {
            // clear all data
            $tables = $assignments->getPageAssignments($event->data['id']);
            foreach($tables as $table) {
                $schemaData = new SchemaData($table, $event->data['id'], time());
                $schemaData->clearData();
            }
        } else {
            // save the provided data
            foreach($this->tosave as $table) {
                $schemaData = new SchemaData($table, $event->data['id'], $event->data['newRevision']);
                $schemaData->saveData($structData[$table]);

                // make sure this schema is assigned
                $assignments->assignPageSchema($event->data['id'], $table);
            }
        }
    }

    /**
     * Validate the given data
     *
     * Catches the Validation exceptions and transforms them into proper messages.
     *
     * Blank values are not validated and always pass
     *
     * @param AbstractBaseType $type
     * @param string $label
     * @param array|string|int $data
     * @return bool true if the data validates, otherwise false
     */
    protected function validate(AbstractBaseType $type, $label, $data) {
        $prefix = sprintf($this->getLang('validation_prefix'), $label);

        $ok = true;
        if(is_array($data)) {
            foreach($data as $value) {
                if(!blank($value)) {
                    try {
                        $type->validate($value);
                    } catch(ValidationException $e) {
                        msg($prefix . $e->getMessage(), -1);
                        $ok = false;
                    }
                }
            }
            return $ok;
        }

        if(!blank($data)) {
            try {
                $type->validate($data);
            } catch(ValidationException $e) {
                msg($prefix . $e->getMessage(), -1);
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Create the form to edit schemadata
     *
     * @param string $tablename
     * @return string The HTML for this schema's form
     */
    protected function createForm($tablename) {
        global $ID;
        global $REV;
        global $INPUT;
        $schema = new SchemaData($tablename, $ID, $REV);
        $schemadata = $schema->getData();

        $structdata = $INPUT->arr(self::$VAR);
        if(isset($structdata[$tablename])) {
            $postdata = $structdata[$tablename];
        } else {
            $postdata = array();
        }

        // we need a short, unique identifier to use in the cookie. this should be good enough
        $schemaid = 'SRCT'.substr(str_replace(array('+', '/'), '', base64_encode(sha1($tablename, true))), 0, 5);
        $html = '<fieldset data-schema="' . $schemaid . '">';
        $html .= '<legend>' . hsc($tablename) . '</legend>';
        foreach($schemadata as $field) {
            $label = $field->getColumn()->getLabel();
            if(isset($postdata[$label])) {
                // posted data trumps stored data
                $field->setValue($postdata[$label]);
            }
            $trans = hsc($field->getColumn()->getTranslatedLabel());
            $name = self::$VAR . "[$tablename][$label]";
            $input = $field->getValueEditor($name);
            $html .= "<label><span class=\"label\">$trans</span><span class=\"input\">$input</span></label>";
        }
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Simple filter to remove blank values
     *
     * @param string $val
     * @return bool
     */
    public function filter($val) {
        return !blank($val);
    }
}

// vim:ts=4:sw=4:et:
