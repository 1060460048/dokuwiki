<?php
namespace plugin\struct\types;

use plugin\struct\meta\ValidationException;

/**
 * Class Integer
 *
 * A field accepting integer numbers only
 *
 * @package plugin\struct\types
 */
class Integer extends AbstractMultiBaseType {

    protected $config = array(
        'format' => '%d',
        'min' => '',
        'max' => ''
    );

    /**
     * Output the stored data
     *
     * @param string|int $value the value stored in the database
     * @param \Doku_Renderer $R the renderer currently used to render the data
     * @param string $mode The mode the output is rendered in (eg. XHTML)
     * @return bool true if $mode could be satisfied
     */
    public function renderValue($value, \Doku_Renderer $R, $mode) {
        $R->cdata(sprintf($this->config['format'], $value));
        return true;
    }

    /**
     * @param int|string $value
     * @throws ValidationException
     */
    public function validate($value) {
        $value = trim($value);

        if((string) $value != (string) intval($value)) {
            throw new ValidationException('Integer needed');
        }

        if($this->config['min'] !== '' && intval($value) <= intval($this->config['min'])) {
            throw new ValidationException('Integer min', intval($this->config['min']));
        }

        if($this->config['max'] !== '' && intval($value) >= intval($this->config['max'])) {
            throw new ValidationException('Integer max', intval($this->config['max']));
        }
    }

}
