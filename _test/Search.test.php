<?php

namespace plugin\struct\test;

use plugin\struct\meta;

spl_autoload_register(array('action_plugin_struct_autoloader', 'autoloader'));

/**
 * Tests for the building of SQL-Queries for the struct plugin
 *
 * @group plugin_struct
 * @group plugins
 *
 */
class Search_struct_test extends \DokuWikiTest {

    protected $pluginsEnabled = array('struct', 'sqlite');

    public function setUp() {
        parent::setUp();

        $sb = new meta\SchemaBuilder(
            'schema1',
            array(
                'new' => array(
                    'new1' => array('label' => 'first', 'class' => 'Text', 'sort' => 10, 'ismulti' => 0, 'isenabled' => 1),
                    'new2' => array('label' => 'second', 'class' => 'Text', 'sort' => 20, 'ismulti' => 1, 'isenabled' => 1),
                    'new3' => array('label' => 'third', 'class' => 'Text', 'sort' => 30, 'ismulti' => 0, 'isenabled' => 1),
                    'new4' => array('label' => 'fourth', 'class' => 'Text', 'sort' => 40, 'ismulti' => 0, 'isenabled' => 1),
                )
            )
        );
        $sb->build();

        $sb = new meta\SchemaBuilder(
            'schema2',
            array(
                'new' => array(
                    'new1' => array('label' => 'afirst', 'class' => 'Text', 'sort' => 10, 'ismulti' => 0, 'isenabled' => 1),
                    'new2' => array('label' => 'asecond', 'class' => 'Text', 'sort' => 20, 'ismulti' => 1, 'isenabled' => 1),
                    'new3' => array('label' => 'athird', 'class' => 'Text', 'sort' => 30, 'ismulti' => 0, 'isenabled' => 1),
                    'new4' => array('label' => 'afourth', 'class' => 'Text', 'sort' => 40, 'ismulti' => 0, 'isenabled' => 1),
                )
            )
        );
        $sb->build();

        $as = new mock\Assignments();

        $as->assignPageSchema('page01', 'schema1');
        $sd = new meta\SchemaData('schema1', 'page01', time());
        $sd->saveData(
            array(
                'first' => 'first data',
                'second' => array('second data', 'more data', 'even more'),
                'third' => 'third data',
                'fourth' => 'fourth data'
            )
        );

        $as->assignPageSchema('page01', 'schema2');
        $sd = new meta\SchemaData('schema2', 'page01', time());
        $sd->saveData(
            array(
                'afirst' => 'first data',
                'asecond' => array('second data', 'more data', 'even more'),
                'athird' => 'third data',
                'afourth' => 'fourth data'
            )
        );

        for($i=10; $i <=20; $i++) {
            $as->assignPageSchema("page$i", 'schema2');
            $sd = new meta\SchemaData('schema2', "page$i", time());
            $sd->saveData(
                array(
                    'afirst' => "page$i first data",
                    'asecond' => array("page$i second data"),
                    'athird' => "page$i third data",
                    'afourth' => "page$i fourth data"
                )
            );
        }
    }

    protected function tearDown() {
        parent::tearDown();

        /** @var \helper_plugin_struct_db $sqlite */
        $sqlite = plugin_load('helper', 'struct_db');
        $sqlite->resetDB();
    }

    public function test_simple() {
        $search = new mock\Search();

        $search->addSchema('schema1');
        $search->addColumn('%pageid%');
        $search->addColumn('first');
        $search->addColumn('second');

        /** @var meta\Value[][] $result */
        $result = $search->execute();

        $this->assertEquals(1, count($result), 'result rows');
        $this->assertEquals(3, count($result[0]), 'result columns');
        $this->assertEquals('page01', $result[0][0]->getValue());
        $this->assertEquals('first data', $result[0][1]->getValue());
        $this->assertEquals(array('second data', 'more data', 'even more'), $result[0][2]->getValue());
    }

    public function test_search() {
        $search = new mock\Search();

        $search->addSchema('schema1');
        $search->addSchema('schema2', 'foo');
        $this->assertEquals(2, count($search->schemas));

        $search->addColumn('first');
        $this->assertEquals('schema1', $search->columns[0]->getTable());
        $this->assertEquals(1, $search->columns[0]->getColref());

        $search->addColumn('afirst');
        $this->assertEquals('schema2', $search->columns[1]->getTable());
        $this->assertEquals(1, $search->columns[1]->getColref());

        $search->addColumn('schema1.third');
        $this->assertEquals('schema1', $search->columns[2]->getTable());
        $this->assertEquals(3, $search->columns[2]->getColref());

        $search->addColumn('foo.athird');
        $this->assertEquals('schema2', $search->columns[3]->getTable());
        $this->assertEquals(3, $search->columns[3]->getColref());

        $search->addColumn('asecond');
        $this->assertEquals('schema2', $search->columns[4]->getTable());
        $this->assertEquals(2, $search->columns[4]->getColref());

        $search->addColumn('doesntexist');
        $this->assertEquals(5, count($search->columns));

        $search->addColumn('%pageid%');
        $this->assertEquals('schema1', $search->columns[5]->getTable());
        $exception = false;
        try {
            $search->columns[5]->getColref();
        } catch(meta\StructException $e) {
            $exception = true;
        }
        $this->assertTrue($exception, "Struct exception expected for accesing colref of PageColumn");

        $search->addSort('first', false);
        $this->assertEquals(1, count($search->sortby));

        $search->addFilter('%pageidid%', '%ag%', '~', 'AND');
        $search->addFilter('second', '%sec%', '~', 'AND');
        $search->addFilter('first', '%rst%', '~', 'AND');



        $result = $search->execute();
        $count  = $search->getCount();

        $this->assertEquals(1, $count, 'result count');
        $this->assertEquals(1, count($result), 'result rows');
        $this->assertEquals(6, count($result[0]), 'result columns');

        /*
        {#debugging
            list($sql, $opts) = $search->getSQL();
            print "\n";
            print_r($sql);
            print "\n";
            print_r($opts);
            print "\n";
            #print_r($result);
        }
        */
    }

    public function test_ranges() {
        $search = new mock\Search();
        $search->addSchema('schema2');

        $search->addColumn('%pageid%');
        $search->addColumn('afirst');
        $search->addColumn('asecond');

        $search->addSort('%pageid%', false);

        /** @var meta\Value[][] $result */
        $result = $search->execute();
        $count  = $search->getCount();

        // check result dimensions
        $this->assertEquals(12, $count, 'result count');
        $this->assertEquals(12, count($result), 'result rows');
        $this->assertEquals(3, count($result[0]), 'result columns');

        // check sorting
        $this->assertEquals('page20', $result[0][0]->getValue());
        $this->assertEquals('page19', $result[1][0]->getValue());
        $this->assertEquals('page18', $result[2][0]->getValue());

        // now add limit
        $search->setLimit(5);
        $result = $search->execute();
        $count  = $search->getCount();

        // check result dimensions
        $this->assertEquals(12, $count, 'result count'); // full result set
        $this->assertEquals(5, count($result), 'result rows'); // wanted result set

        // check the values
        $this->assertEquals('page20', $result[0][0]->getValue());
        $this->assertEquals('page16', $result[4][0]->getValue());

        // now add offset
        $search->setOffset(5);
        $result = $search->execute();
        $count  = $search->getCount();

        // check result dimensions
        $this->assertEquals(12, $count, 'result count'); // full result set
        $this->assertEquals(5, count($result), 'result rows'); // wanted result set

        // check the values
        $this->assertEquals('page15', $result[0][0]->getValue());
        $this->assertEquals('page11', $result[4][0]->getValue());
    }

}
