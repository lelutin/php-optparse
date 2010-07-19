PHPOptparse -- A command-line option parser for PHP

PHPOptparse is a PHP port of Python's optparse module. Its interface tries to
be as similar as possible to that of its Python counterpart.

How to use
==========

Simply drop the optparse.php file in your project directory and include() it.
Then, create an OptionParser object and add options with the add_option()
method. When all options are set up, parse the options with the parse_args()
method. The object returned from parse_args will contain all the values from
the command line as attributes corresponding to names given to the "dest"
parameter to add_option().

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

License
=======

The PHPOptparse library is licensed under the GNU GPLv2. The full license text
should be shipped with the code in a file named LICENSE. If not, you can find
the full text online at the following URL:

http://www.gnu.org/licenses/gpl-2.0.txt
