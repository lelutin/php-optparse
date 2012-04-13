PHPOptparse -- A command-line option parser for PHP

PHPOptparse is a PHP port of Python's optparse module. Its interface tries to
be as similar as possible to that of its Python counterpart.

DISCLAIMER
==========

I personally won't be using this module anymore. I've just ported another
module that achieves the same things as this one, but that is _a lot_ simpler
(about 1/3 the amount of lines of code) and that makes code an awsome lot
cleaner.  Check out this other module:

https://github.com/lelutin/php-options

However, if others continue to fix bugs or implement cool things, I'll still be
merging patches to this code so that it's not completely dead.

How to use
==========

Simply drop the optparse.php file in your project directory and include() it.
Then, create an OptionParser object and add options with the add\_option()
method. When all options are set up, parse the options with the parse\_args()
method. The object returned from parse\_args will contain all the values from
the command line as attributes corresponding to names given to the "dest"
parameter to add\_option().

Example:

    // called with: ./my_program -b 4

    $option_parser = new OptionParser(array("version"=>"meuh 1.2.3", "description" => "lalla", "epilog"=>"patate"));
    $option_parser->add_option(array(
        "-b", "--booh",
        "dest" => "gah",
        "metavar" => "<the thing>",
        "type" => "int"
    ));
    $options = $option_parser->parse_args($argv);

    // here $options->gah contains 4

Running the tests
=================

The 't' directory contains unit tests for classes contained in the PHPOptparse
library. To run the tests, use phpunit in the following manner:

    phpunit OptionTest

License
=======

The PHPOptparse library is licensed under the GNU GPLv2. The full license text
should be shipped with the code in a file named LICENSE. If not, you can find
the full text online at the following URL:

http://www.gnu.org/licenses/gpl-2.0.txt
