<?php
/**
 * DokuWiki Plugin struct (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
use plugin\struct\meta\Assignments;
use plugin\struct\meta\SchemaData;

if (!defined('DOKU_INC')) die();

class syntax_plugin_struct_output extends DokuWiki_Syntax_Plugin {
    /**
     * @return string Syntax mode type
     */
    public function getType() {
        return 'substition';
    }
    /**
     * @return string Paragraph type
     */
    public function getPType() {
        return 'block';
    }
    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        return 155;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * We do not connect any pattern here, because the call to this plugin is not
     * triggered from syntax but our action component
     *
     * @asee action_plugin_struct_output
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {

    }


    /**
     * Handle matches of the struct syntax
     *
     * @param string $match The match of the syntax
     * @param int    $state The state of the handler
     * @param int    $pos The position in the document
     * @param Doku_Handler    $handler The handler
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler){
        // this is never called
        return array();
    }

    /**
     * Render schema data
     *
     * Currently completely renderer agnostic
     *
     * @todo add some classes for nicer styling when $mode = 'xhtml'
     * @todo we currently have no schema headlines
     *
     * @param string         $mode      Renderer mode
     * @param Doku_Renderer  $R         The renderer
     * @param array          $data      The data from the handler() function
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $R, $data) {
        global $ID;
        global $INFO;
        global $REV;
        if($ID != $INFO['id']) return true;

        $assignments = new Assignments();
        $tables = $assignments->getPageAssignments($ID);
        if(!$tables) return true;

        $R->table_open();
        $R->tabletbody_open();
        foreach($tables as $table) {
            $schemadata = new SchemaData($table, $ID, $REV);
            $data = $schemadata->getData();

            foreach($data as $field) {
                $R->tablerow_open();
                $R->tableheader_open();
                $R->cdata($field->getColumn()->getLabel());
                $R->tableheader_close();
                $R->tablecell_open();
                $field->render($R, $mode);
                $R->tablecell_close();
                $R->tablerow_close();
            }
        }
        $R->tabletbody_close();
        $R->table_close();

        return true;
    }
}

// vim:ts=4:sw=4:et:
