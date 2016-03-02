<?php

namespace plugin\struct\meta;

/**
 * Class Assignments
 *
 * Manages the assignment of schemas (table names) to pages and namespaces
 *
 * @package plugin\struct\meta
 */
class Assignments {

    /** @var \helper_plugin_sqlite|null */
    protected $sqlite;

    /** @var  array All the assignments patterns */
    protected $patterns;

    /**
     * Assignments constructor.
     */
    public function __construct() {
        /** @var \helper_plugin_struct_db $helper */
        $helper = plugin_load('helper', 'struct_db');
        $this->sqlite = $helper->getDB();

        if($this->sqlite) $this->loadPatterns();
    }

    /**
     * Load existing assignment patterns
     */
    protected function loadPatterns() {
        $sql = 'SELECT * FROM schema_assignments_patterns ORDER BY pattern';
        $res = $this->sqlite->query($sql);
        $this->patterns = $this->sqlite->res2arr($res);
        $this->sqlite->res_close($res);
    }

    /**
     * Add a new assignment pattern to the pattern table
     *
     * @param string $pattern
     * @param string $table
     * @return bool
     */
    public function addPattern($pattern, $table) {
        // add the pattern
        $sql = 'REPLACE INTO schema_assignments_patterns (pattern, tbl) VALUES (?,?)';
        $ok = (bool) $this->sqlite->query($sql, array($pattern, $table));

        // reload patterns
        $this->loadPatterns();

        // fetch all pages where the schema isn't assigned, yet
        $sql = 'SELECT pid FROM schema_assignments WHERE tbl != ? OR assigned != 1';
        $res = $this->sqlite->query($sql, $table);
        $pagerows = $this->sqlite->res2arr($res);
        $this->sqlite->res_close($res);

        // reevalute the pages and assign when needed
        foreach($pagerows as $row) {
            $tables = $this->getPageAssignments($row['pid'], true);
            if(in_array($table, $tables)) {
                $this->assignPageSchema($row['pid'], $table);
            }
        }

        return $ok;
    }

    /**
     * Remove an existing assignment pattern from the pattern table
     *
     * @param string $pattern
     * @param string $table
     * @return bool
     */
    public function removePattern($pattern, $table) {
        // remove the pattern
        $sql = 'DELETE FROM schema_assignments_patterns WHERE pattern = ? AND tbl = ?';
        $ok = (bool) $this->sqlite->query($sql, array($pattern, $table));

        // reload patterns
        $this->loadPatterns();

        // fetch possibly affected pages
        $sql = 'SELECT pid FROM schema_assignments WHERE tbl = ?';
        $res = $this->sqlite->query($sql, $table);
        $pagerows = $this->sqlite->res2arr($res);
        $this->sqlite->res_close($res);

        // reevalute the pages and unassign when needed
        foreach($pagerows as $row) {
            $tables = $this->getPageAssignments($row['pid'], true);
            if(!in_array($table, $tables)) {
                $this->deassignPageSchema($row['pid'], $table);
            }
        }

        return $ok;
    }

    /**
     * Clear all patterns - deassigns all pages
     *
     * This is mostly useful for testing and not used in the interface currently
     *
     * @return bool
     */
    public function clear() {
        $sql = 'DELETE FROM schema_assignments_patterns';
        $ok = (bool) $this->sqlite->query($sql);

        $sql = 'UPDATE schema_assignments SET assigned = 0';
        $ok = $ok && (bool) $this->sqlite->query($sql);

        // reload patterns
        $this->loadPatterns();

        return $ok;
    }

    /**
     * Add page to assignments
     *
     * @param string $page
     * @param string $table
     * @return bool
     */
    protected function assignPageSchema($page, $table) {
        $sql = 'REPLACE INTO schema_assignments (pid, tbl, assigned) VALUES (?, ?, 1)';
        return (bool) $this->sqlite->query($sql, array($page, $table));
    }

    /**
     * Remove page from assignments
     *
     * @param string $page
     * @param string $table
     * @return bool
     */
    protected function deassignPageSchema($page, $table) {
        $sql = 'REPLACE INTO schema_assignments (pid, tbl, assigned) VALUES (?, ?, 0)';
        return (bool) $this->sqlite->query($sql, array($page, $table));
    }

    /**
     * Get the whole pattern table
     *
     * @return array
     */
    public function getAllPatterns() {
        return $this->patterns;
    }

    /**
     * Returns a list of table names assigned to the given page
     *
     * @param string $page
     * @param bool $checkpatterns Should the current patterns be re-evaluated?
     * @return \string[] tables assigned
     */
    public function getPageAssignments($page, $checkpatterns=true) {
        $tables = array();
        $page = cleanID($page);

        if($checkpatterns) {
            // evaluate patterns
            $pns = ':' . getNS($page) . ':';
            foreach($this->patterns as $row) {
                if($this->matchPagePattern($row['pattern'], $page, $pns)) {
                    $tables[] = $row['tbl'];
                }
            }
        } else {
            // just select
            $sql = 'SELECT tbl FROM schema_assignments WHERE pid = ? AND assigned = 1';
            $res = $this->sqlite->query($sql, array($page));
            $list = $this->sqlite->res2arr($res);
            $this->sqlite->res_close($res);
            foreach($list as $row) {
                $tables[] = $row['tbl'];
            }
        }

        return array_unique($tables);
    }

    /**
     * Check if the given pattern matches the given page
     *
     * @param string $pattern the pattern to check against
     * @param string $page the cleaned pageid to check
     * @param string|null $pns optimization, the colon wrapped namespace of the page, set null for automatic
     * @return bool
     */
    protected function matchPagePattern($pattern, $page, $pns = null) {
        if(trim($pattern,':') == '**') return true; // match all

        if(is_null($pns)) {
            $pns = ':' . getNS($page) . ':';
        }

        $ans = ':' . cleanID($pattern) . ':';

        if(substr($pattern, -2) == '**') {
            // upper namespaces match
            if(strpos($pns, $ans) === 0) {
                return true;
            }
        } else if(substr($pattern, -1) == '*') {
            // namespaces match exact
            if($ans == $pns) {
                return true;
            }
        } else {
            // exact match
            if(cleanID($pattern) == $page) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all tables of schemas that existed and stored data for the page back then
     *
     * @deprecated because we're always only interested in the current state of affairs, even when restoring.
     *
     * @param string $page
     * @param string $ts
     * @return array
     */
    public function getHistoricAssignments($page, $ts) {
        $sql = "SELECT DISTINCT tbl FROM schemas WHERE ts <= ? ORDER BY ts DESC";
        $res = $this->sqlite->query($sql, $ts);
        $tables = $this->sqlite->res2arr($res);
        $this->sqlite->res_close($res);

        $assigned = array();
        foreach($tables as $row) {
            $table = $row['tbl'];
            /** @noinspection SqlResolve */
            $sql = "SELECT pid FROM data_$table WHERE pid = ? AND rev <= ? LIMIT 1";
            $res = $this->sqlite->query($sql, $page, $ts);
            $found = $this->sqlite->res2arr($res);
            $this->sqlite->res_close($res);

            if($found) $assigned[] = $table;
        }

        return $assigned;
    }
}
