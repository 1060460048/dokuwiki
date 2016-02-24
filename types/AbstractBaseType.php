<?php
namespace plugin\struct\types;

use dokuwiki\Form\Form;
use plugin\struct\meta\ValidationException;

/**
 * Class AbstractBaseType
 *
 * This class represents a basic type that can be configured to be used in a Schema. It is the main
 * part of a column definition as defined in meta\Column
 *
 * This defines also how the content of the coulmn will be entered and formatted.
 *
 * @package plugin\struct\types
 * @see Column
 */
abstract class AbstractBaseType {

    /**
     * @var array current config
     */
    protected $config = array();

    /**
     * @var array config keys that should not be cleaned despite not being in $config
     */
    protected $keepconfig = array('translation');

    /**
     * @var string label for the field
     */
    protected $label = '';

    /**
     * @var bool is this a multivalue field?
     */
    protected $ismulti = false;

    /**
     * @var int the type ID
     */
    protected $tid = 0;

    /**
     * AbstractBaseType constructor.
     * @param array|null $config The configuration, might be null if nothing saved, yet
     * @param string $label The label for this field (empty for new definitions=
     * @param bool $ismulti Should this field accept multiple values?
     * @param int $tid The id of this type if it has been saved, yet
     */
    public function __construct($config = null, $label = '', $ismulti = false, $tid = 0) {
        // initialize the configuration, ignoring all keys that are not supposed to be here
        if(!is_null($config)) {
            foreach($config as $key => $value) {
                if(isset($this->config[$key]) || in_array($key, $this->keepconfig)) {
                    $this->config[$key] = $value;
                }
            }
        }

        $this->initTransConfig();
        $this->label = $label;
        $this->ismulti = (bool) $ismulti;
        $this->tid = $tid;
    }

    /**
     * Add the translation keys to the configuration
     *
     * This checks if a configuration for the translation plugin exists and if so
     * adds all configured languages to the config array. This ensures all types
     * can have translatable labels.
     */
    protected function initTransConfig() {
        global $conf;
        $lang = $conf['lang'];
        if(isset($conf['plugin']['translation']['translations'])) {
            $lang .= ' ' . $conf['plugin']['translation']['translations'];
        }
        $langs = explode(' ', $lang);
        $langs = array_map('trim', $langs);
        $langs = array_filter($langs);
        $langs = array_unique($langs);

        if(!isset($this->config['translation'])) $this->config['translation'] = array();
        foreach($langs as $lang) {
            if(!isset($this->config['translation'][$lang])) $this->config['translation'][$lang] = '';
        }
    }

    /**
     * Returns data as associative array
     *
     * @return array
     */
    public function getAsEntry() {
        return array(
            'config' => json_encode($this->config),
            'label' => $this->label,
            'ismulti' => $this->ismulti,
            'class' => $this->getClass()
        );
    }

    /**
     * The class name of this type (no namespace)
     * @return string
     */
    public function getClass() {
        return substr(get_class($this), 20);
    }

    /**
     * Return the current configuration for this type
     *
     * @return array
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * @return boolean
     */
    public function isMulti() {
        return $this->ismulti;
    }

    /**
     * @return string
     */
    public function getLabel() {
        return $this->label;
    }

    /**
     * Returns the translated label for this type
     *
     * Uses the current language as determined by $conf['lang']. Falls back to english
     * and then to the Schema label
     *
     * @return string
     */
    public function getTranslatedLabel() {
        global $conf;
        $lang = $conf['lang'];
        if(!blank($this->config['translation'][$lang])) {
            return $this->config['translation'][$lang];
        }
        if(!blank($this->config['translation']['en'])) {
            return $this->config['translation']['en'];
        }
        return $this->label;
    }

    /**
     * @return int
     */
    public function getTid() {
        return $this->tid;
    }

    /**
     * Split a single value into multiple values
     *
     * This function is called on saving data when only a single value instead of an array
     * was submitted.
     *
     * Types implementing their own @see multiValueEditor() will probably want to override this
     *
     * @param string $value
     * @return array
     */
    public function splitValues($value) {
        return array_map('trim', explode(',', $value));
    }

    /**
     * Return the editor to edit multiple values
     *
     * Types can override this to provide a better alternative than multiple entry fields
     *
     * @param string $name the form base name where this has to be stored
     * @param string[] $values the current values
     * @return string html
     */
    public function multiValueEditor($name, $values) {
        $html = '';
        foreach($values as $value) {
            $html .= '<div class="multiwrap">';
            $html .= $this->valueEditor($name . '[]', $value);
            $html .= '</div>';
        }
        // empty field to add
        $html .= '<div class="newtemplate">';
        $html .= '<div class="multiwrap">';
        $html .= $this->valueEditor($name . '[]', '');
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Return the editor to edit a single value
     *
     * @param string $name the form name where this has to be stored
     * @param string $value the current value
     * @return string html
     */
    public function valueEditor($name, $value) {
        $name = hsc($name);
        $value = hsc($value);
        $html = "<input name=\"$name\" value=\"$value\" />";
        return "$html";
    }

    /**
     * Output the stored data
     *
     * @param string|int $value the value stored in the database
     * @param \Doku_Renderer $R the renderer currently used to render the data
     * @param string $mode The mode the output is rendered in (eg. XHTML)
     * @return bool true if $mode could be satisfied
     */
    public function renderValue($value, \Doku_Renderer $R, $mode) {
        $R->cdata($value);
        return true;
    }

    /**
     * format and return the data
     *
     * @param int[]|string[] $values the values stored in the database
     * @param \Doku_Renderer $R the renderer currently used to render the data
     * @param string $mode The mode the output is rendered in (eg. XHTML)
     * @return bool true if $mode could be satisfied
     */
    public function renderMultiValue($values, \Doku_Renderer $R, $mode) {
        $len = count($values);
        for($i = 0; $i < $len; $i++) {
            $this->renderValue($values[$i], $R, $mode);
            if($i < $len - 1) {
                $R->cdata(', ');
            }
        }
        return true;
    }

    /**
     * This function builds a where clause for this column, comparing
     * the current value stored in $column with $value. Types can use it to do
     * clever things with the comparison.
     *
     * This default implementation is probably good enough for most basic types
     *
     * @param string $column The column name to us in the SQL
     * @param string $comp The comparator @see Search::$COMPARATORS
     * @param string $value
     * @return array Tuple with the SQL and parameter array
     */
    public function compare($column, $comp, $value) {
        switch ($comp) {
            case '~':
                $sql = "$column LIKE ?";
                $opt = array($value);
                break;
            case '!~':
                $sql = "$column NOT LIKE ?";
                $opt = array($value);
                break;
            default:
                $sql = "$column $comp ?";
                $opt = array($value);
        }

        return array($sql, $opt);
    }

    /**
     * Validate a single value
     *
     * This function needs to throw a validation exception when validation fails.
     * The exception message will be prefixed by the appropriate field on output
     *
     * @param string|int $value
     * @throws ValidationException
     */
    public function validate($value) {
        // nothing by default - we allow everything
    }
}
