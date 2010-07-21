<?php
/*********************************
 * Unit tests for the Option class
 *********************************/

require_once "PHPUnit/Framework.php";
require_once "../optparse.php";

function create_Option($args) { $opt = new Option($args); }

class OptionTest extends PHPUnit_Framework_TestCase {

    var $opt;

/*    function setUp() {
        $settings = array(
            "-o", "--option",
        );

        $this->opt = new Option($settings);
    }

    function tearDown() {
        unset($this->opt);
    }*/

    function test_constructor_default_values() {
        // $this->opt has already gone through the constructor.
        $opt = new Option( array("-o", "--option") );

        // Default values are in place
        $this->assertEquals( array(), $opt->disabled_strings );
        $this->assertEquals( array("-o", "--option"), $opt->option_strings );
        $this->assertEquals( "string", $opt->type );
        $this->assertNull( $opt->choices );
        $this->assertEquals( "option", $opt->dest );
        $this->assertEquals( 1, $opt->nargs );
        $this->assertEquals( "~~~NO~DEFAULT~~~", $opt->default );
        $this->assertEquals( "store", $opt->action );
        $this->assertEquals( "", $opt->help );
        $this->assertNull( $opt->callback );
        $this->assertNull( $opt->callback_args );
        $this->assertEquals( "~~~NO~DEFAULT~~~", $opt->const );
        $this->assertEquals( "OPTION", $opt->metavar );
    }

    function test_constructor_override_no_conflict() {
        // $this->opt has already gone through the constructor.
        $opt = new Option( array("-o", "--option", "--new-option",
            "dest" => "this_option",
            "metavar" => "MOO",
            "nargs" => 2,
            "action" => "append_const",
            "help" => "help me out!",
            "const" => 42,
            "default" => 10
        ) );

        $this->assertEquals( array(), $opt->disabled_strings );
        $this->assertEquals( array("-o", "--option", "--new-option"), $opt->option_strings );
        $this->assertNull( $opt->type );
        $this->assertNull( $opt->choices );
        $this->assertEquals( "this_option", $opt->dest );
        $this->assertEquals( 2, $opt->nargs );
        $this->assertEquals( 10, $opt->default );
        $this->assertEquals( "append_const", $opt->action );
        $this->assertEquals( "help me out!", $opt->help );
        $this->assertNull( $opt->callback );
        $this->assertNull( $opt->callback_args );
        $this->assertEquals( 42, $opt->const );
        $this->assertEquals( "MOO", $opt->metavar );

        unset($opt);

        // The arguments that were not tested yet
        $opt = new Option(array("-b",
            "action" => "callback",
            "callback" => "create_Option",
            "callback_args" => array(1,2,3,4,"foo"=>5),
            "callback_kwargs" => array("blah" => 6)
        ) );

        $this->assertEquals( array(), $opt->disabled_strings );
        $this->assertEquals( array("-b"), $opt->option_strings );
        $this->assertNull( $opt->type );
        $this->assertNull( $opt->choices );
        $this->assertNull( $opt->dest );
        $this->assertEquals( 1, $opt->nargs );
        $this->assertEquals( "~~~NO~DEFAULT~~~", $opt->default );
        $this->assertEquals( "callback", $opt->action );
        $this->assertEquals( "", $opt->help );
        $this->assertEquals( "create_Option", $opt->callback );
        $this->assertEquals( array(1,2,3,4,"foo"=>5,"blah"=>6), $opt->callback_args );
        $this->assertEquals( "~~~NO~DEFAULT~~~", $opt->const );
        $this->assertEquals( "", $opt->metavar );
    }

    // $func can be a string (the name of a global function) or an array with a
    // reference to an object and a string for the object's method name.
    function assertThrows($exc_type, $func, $func_args) {
        $this->numAssertions += 1; // Make this assertion visible in the report

        try {
            $opt = call_user_func_array($func, $func_args);
        }
        catch (Exception $e) {
            if ( get_class($e) == $exc_type ) {
                return; // Success
            }
            else {
                $this->fail("Exception '".get_class($e)."' caught, expected to catch '$exc_type'");
            }
        }

        $this->fail("Exception '$exc_type' not thrown by function '$func'");
    }

    function test_constructor_override_has_conflict() {
        // all the situations verified inside of _verify_settings_dependencies
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"store","const"=>2)) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","type"=>"baaah")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"store_true","type"=>"int")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","type"=>"choice")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","type"=>"choice","choices"=>42)) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","type"=>"int","choices"=>42)) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"callback")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"callback","callback"=>"blah")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"callback","callback"=>"create_Option","callback_args"=>true)) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"store","callback"=>"create_Option")) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a","action"=>"store","callback_args"=>true)) );
    }

    function test_constructor_invalid_args() {
        $this->assertThrows("InvalidArgumentException", "create_Option", array(array()) );
        $this->assertThrows("InvalidArgumentException", "create_Option", array(array("-a", "nargs"=>-1)) );
        $this->assertThrows("OptionError", "create_Option", array(array("-a", "unknown"=>"something")) );
    }
}

?>
