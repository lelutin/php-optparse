<?php
/**
 * Option parser.
 *
 * Easily parse command line arguments in PHP. This parser has the same
 * interface as the Python "optparse" module.
 *
 * Example usage:
 *   $parser = new OptionParser();
 *   $parser->add_option(array("-f", "--foo", "dest"=>"bar"));
 *   $values = $parser->parse_args($argv);
 */
if (! defined("__OPTPARSE_PHP") ) {
define("__OPTPARSE_PHP", "");

define("NO_SUCH_OPT_ERROR", 1);
define("WRONG_VALUE_COUNT_ERROR", 2);
define("OPTION_VALUE_ERROR", 3);

// Default value for an option can be Null. We need an explicit no_default value
define("NO_DEFAULT", "~~~NO~DEFAULT~~~");
// Special value for help to suppress its output for an option.
define("SUPPRESS_HELP", "~~~SUPPRESS~HELP~~~");

// Default translation simply returns the string as-is
function _no_translation($string) { return $string; }

// Define this constant before inclusion, or redefine it with the translation
// function name.
// The function should take a string in and return its translated form.
if (! defined("_OPTPARSE_T") ) {
    define("_OPTPARSE_T", "_no_translation");
}

function _translate($string, $variables=array(),
                    $translator=_OPTPARSE_T) {
    assert( function_exists($translator) );
    $new_text = $translator($string);

    // Use values from $variables to replace pattends of the form %(name)s
    foreach ($variables as $name => $var) {
        $new_text = preg_replace("/%\($name\)s/", $var, $new_text);
    }

    return $new_text;
}

/**
 * Retrieve an element and remove it from an array.
 *
 * If the key is not present in the array, return the default value, given in
 * the third argument. If the third argument is omitted, the default value is
 * Null.
 *
 * @return mixed: value from the array or default value if key is not in array.
 * @author Gabriel Filion
 **/
function _array_pop_elem(&$array, $key, $default=null) {
    assert( is_string($key) );
    if ( ! array_key_exists($key, $array) ) {
        $value = $default;
    }
    else {
        $value = $array[$key];
    }

    if ( array_key_exists($key, $array) ) {
        unset($array[$key]);
    }

    return $value;
}

/**
 * Utility class for parsing arguments from the Command Line Interface
 *
 * This class has one difference from its Python counterpart: it has no
 * "option_list" argument. This argument is currently marked as deprecated in
 * Python's optparse module in favor of using the add_option method.
 *
 * @author Gabriel Filion <lelutin@gmail.com>
 */
class OptionParser {

    protected $standard_option_list = array();

    function OptionParser($settings=array()) {
        $this->_positional = array();
        $this->option_list = $this->standard_option_list;

        // This must come first so that calls to add_option can succeed.
        $this->option_class = _array_pop_elem(
            $settings,
            "option_class",
            "Option"
        );
        if ( ! is_string($this->option_class) ) {
            $msg = _translate("The setting \"option_class\" must be a string");
            throw new InvalidArgumentException($msg);
        }

        $default_usage = _translate("%prog [options]");
        $this->set_usage( _array_pop_elem($settings, "usage", $default_usage) );

        $this->description = _array_pop_elem($settings, "description", "");
        $this->epilog = _array_pop_elem($settings, "epilog", "");

        $this->defaults = array();

        $add_help_option = _array_pop_elem($settings, "add_help_option", true);
        if ($add_help_option) {
            $this->add_option( array(
                "-h","--help",
                "action" => "help",
                "help" => _translate("show this help message and exit")
            ) );
        }

        $this->version = _array_pop_elem($settings, "version", "");
        if ($this->version) {
            $this->add_option( array(
                "--version",
                "action" => "version",
                "help" => _translate("show program's version number and exit")
            ) );
        }

        $this->set_conflict_handler(_array_pop_elem(
            $settings,
            "conflict_handler",
            "error"
        ) );

        $this->prog = _array_pop_elem(
            $settings,
            "prog",
            basename($_SERVER['SCRIPT_FILENAME']) // name of the executable
        );

        $this->formatter = _array_pop_elem($settings, "formatter", Null);
        if ($this->formatter == Null) {
            $this->formatter = new IndentedHelpFormatter();
        }

        // Still some settings left? we don't know about them. yell
        if ( ! empty($settings) ) {
            throw new OptionError($settings);
        }
    }

    /**
     * Add an option that the parser must recognize.
     *
     * The argument can be either an array with settings for the option class's
     * constructor, or an Option instance.
     *
     * @return Option object
     * @throws InvalidArgumentException: if argument isn't an array or an Option
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function add_option($settings) {
        if ( is_array($settings) ) {
            $option_class = $this->option_class;
            $new_option = new $option_class($settings);
        }
        else if ( is_a($settings, Option) ) {
            $new_option = $settings;
        }
        else {
            $vals = array("arg" => $settings);
            $msg = _translate("not an Option instance: %(arg)s", $vals);
            throw new InvalidArgumentException($msg);
        }

        // Resolve conflict with the right conflict handler
        foreach ( $new_option->option_strings as $name ) {
            $option = $this->get_option($name);

            if ( $option !== Null ) {
                if ( $this->conflict_handler == "resolve" ) {
                    $this->_resolve_option_conflict($option, $name, $this);
                }
                else {
                    throw new OptionConflictError($name);
                }
            }
        }

        $this->option_list[] = $new_option;

        // Option has a destination. we need a default value
        if ($new_option->dest !== Null) {
            if ($new_option->default !== NO_DEFAULT) {
                $this->defaults[$new_option->dest] = $new_option->default;
            }
            else if ( ! array_key_exists($new_option->dest, $this->defaults) ) {
                $this->defaults[$new_option->dest] = Null;
            }
        }

        return $new_option;
    }

    /**
     * Search for an option name in current options.
     *
     * Given an option string, search for the Option object that uses this
     * string. If the option cannot be found, return Null.
     *
     * @return Option object: when the option is found
     * @return Null: when the option is not found
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_option($text) {
        $found = Null;

        foreach ($this->option_list as $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                $found = $opt;
                break;
            }
        }

        return $found;
    }

    /**
     * Verify presence of an option in the parser.
     *
     * Given an option string, find out if one of the parser's options uses this
     * string. It is a convenient way to verify that an option was already
     * added.
     *
     * @return boolean: true if option is present, false if not
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function has_option($text) {
        foreach ($this->option_list as $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the option that is mapped to the given string.
     *
     * If the option uses other strings of text, those strings become invalid
     * (unused). If the text does not correspond to an option, a
     * OutOfBoundsException is thrown.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function remove_option($text) {
        $found = false;

        foreach ($this->option_list as $key => $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                $strings = $opt->option_strings;

                unset( $this->option_list[$key] );
                $found = true;

                $this->_reenable_option_strings($strings);
                break;
            }
        }

        if (! $found) {
            $vals = array("option" => $text);
            $msg = _translate(
                "Option \"%(option)s\" does not exist.",
                $vals
            );

            throw new OutOfBoundsException($msg);
        }
    }

    /**
     * Set the usage text.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function set_usage($new_usage) {
        $this->usage = $new_usage;
    }

    /**
     * Retrieve the usage string.
     *
     * @return String
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_usage() {
        // Replace occurences of %prog to the program name
        $usage = $this->formatter->format_usage(
            preg_replace(
                "/\%prog/",
                $this->get_prog_name(),
                $this->usage
            )
        );

        return $usage;
    }

    /**
     * Print usage.
     *
     * Default output stream is stdout. To change it, pass another stream as
     * argument.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function print_usage($stream=STDOUT) {
        fprintf($stream, $this->get_usage() );
    }

    /**
     * Print the whole help message as seen with option -h.
     *
     * Default output stream is stdout. To change it, pass another stream as
     * argument.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function print_help($stream=STDOUT) {
        // TODO encode the string from format_help to ensure it is suitable for
        // output.
        fprintf($stream, $this->format_help() );
    }

    /**
     * Format the help string, ready for output.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_help($formatter=Null) {
        if ($formatter == Null) {
            $formatter = $this->formatter;
        }

        $result = "";

        if ($this->usage) {
            $result .= $this->get_usage() . "\n";
        }
        if ($this->description) {
            $result .= $this->format_description($formatter) . "\n";
        }

        $result .= $this->format_option_help($formatter);
        $result .= $this->format_epilog($formatter);

        return $result;
    }

    /**
     * Format the epilog string, ready for output.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_epilog($formatter) {
        return $formatter->format_epilog($this->epilog);
    }

    /**
     * Format the description, ready for output.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_description($formatter) {
        return $formatter->format_description($this->get_description() );
    }

    /**
     * Format a string with help for all the options, ready for output.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_option_help($formatter=Null) {
        if ($formatter == Null) {
            $formatter = $this->formatter;
        }

        $result = array();

        $formatter->store_option_strings($this);

        array_push(
            $result,
            $formatter->format_heading(_translate("Options"))
        );
        $formatter->indent();
        if (! empty($this->option_list)) {
            //XXX change this call when class hierarchy is settled.
            array_push(
                $result,
                OptionContainer_format_option_help($this, $formatter)
            );
            array_push($result, "\n");
        }
        /*for group in self.option_groups:
            result.append(group.format_help(formatter))
            result.append("\n")*/
        $formatter->dedent();

        // Drop the last "\n", or the header if no options or option groups:
        array_pop($result);

        return join("", $result);
    }

    /**
     * Print version information message.
     *
     * Default output stream is stdout. To change it, pass another stream as
     * argument.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function print_version($stream=STDOUT) {
        // Replace occurences of %prog to the program name
        $version = preg_replace(
            "/\%prog/",
            $this->get_prog_name(),
            $this->get_version()
        );

        fprintf($stream, $version. "\n\n" );
    }

    /**
     * Retrieve the program name as shown by usage.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_prog_name() {
        return $this->prog;
    }

    /**
     * Retrieve the description.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_description() {
        return $this->description;
    }

    /**
     * Retrieve the version tag.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_version() {
        return $this->version;
    }

    /**
     * Append an array or a value to an array.
     *
     * Strangely, PHP has no function to simply append (not merge) an array to
     * another one. This provides for this lacking feature.
     *
     * The first array is modified in place, so nothing is returned.
     *
     * This function discards keys from the second array. To conserve the keys,
     * use array_merge.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _array_append(&$array, $appended) {
        if ( ! is_array($appended) ) {
            $array[] = $appended;
        }

        foreach ( $appended as $value ) {
            $array[] = $value;
        }
    }

    /**
     * Parse command line arguments.
     *
     * Given an array of arguments, parse them and create an object containing
     * expected values and positional arguments.
     *
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function parse_args($argv, $values=Null){
        // Pop out the first argument, it is assumed to be the command name
        array_shift($argv);

        if ( $values !== Null && ! is_array($values) ) {
            $msg = _translate("Default values must be in an associative array");
            throw new InvalidArgumentException($msg);
        }

        $this->values = array();

        if ($values === Null) {
            $this->values = $this->get_default_values();
        }
        else {
            // Get a copy of default values and update the array
            $this->values = array_merge($this->get_default_values(), $values);
        }

        $rargs = $argv;

        $positional = array();
        while ( ! empty($rargs) ){
            $arg = array_shift($rargs);

            // Stop processing on a -- argument
            if ( $arg == "--" ) {
                // All remaining arguments are positional
                $this->_array_append($positional, $rargs);
                break;
            }

            // Options should begin with a dash. All else is positional
            // A single dash alone is also a positional argument
            if ( substr($arg, 0, 1) != "-" || strlen($arg) == 1) {
                $positional[] = $arg;
            }
            else if ( substr($arg, 0, 2) == "--" ) {
                $this->_process_long_option($arg, $rargs, $this->values);
            }
            else {
                // values will be removed from $rargs during this process
                $this->_process_short_options($arg, $rargs, $this->values);
            }
        }

        return new Values($this->values, $positional);
    }

    /**
     * Set the option conflict handler.
     *
     * Conflict handler can be one of "error" or "resolve".
     *
     * @return void
     * @throws InvalidArgumentException on invalid handler name
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function set_conflict_handler($handler) {
        if ( ! in_array( $handler, array("error", "resolve") ) ) {
            $msg = _translate(
                "The conflict handler must be one of \"error\" or \"resolve\""
            );
            throw new InvalidArgumentException($msg);
        }

        $this->conflict_handler = $handler;
    }

    /**
     * Get the list of default values.
     *
     * @return array
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function get_default_values() {
        return $this->defaults;
    }

    /**
     * Set default value for only one option.
     *
     * Default values must have a key that corresponds to the "dest" argument of
     * an option.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function set_default($dest, $value) {
        $this->defaults[$dest] = $value;
    }

    /**
     * Set default values for multiple destinations.
     *
     * Default values must have a key that corresponds to the "dest" argument of
     * an option. Calling this function is the preferred way of setting default
     * values for options, since multiple options can share the same
     * destination.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function set_defaults($values) {
        $this->defaults = array_merge($this->defaults, $values);
    }

    /**
     * Exit program with an error message and return code.
     *
     * $text should already be translated when given to this function.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function error($text, $code = 1) {
        $this->print_usage(STDERR);

        $prog = basename($_SERVER['SCRIPT_FILENAME']);

        $l10n_error = _translate("error");

        fprintf(STDERR, "$prog: $l10n_error: $text\n");
        exit($code);
    }

    /**
     * Process a long option.
     *
     * Long options that expect value(s) will get them from the next arguments
     * given on the command line. The first value can also be appended to them
     * with = as a separator.
     *
     * Examples:
     *     program --enable-this
     *     program --option=value
     *     program --option=value1 value2
     *     program --option value1 value2
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _process_long_option($argument, &$rargs, &$values) {
        $key_value = explode("=", $argument, 2);
        $arg_text = $key_value[0];

        $option = $this->_get_known_option($arg_text);

        // Add the first value if it was appended to the arg with =
        if ( count($key_value) > 1 ) {
            // Option didn't expect this value
            if ($option->nargs < 1) {
                $vals = array("option" => $arg_text);
                $msg = _translate(
                    "%(option)s option does not take a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

            array_unshift($rargs, $key_value[1]);
        }

        $this->_process_option($option, $rargs, $arg_text, $values);
    }

    /**
     * Process a conglomerate of short options.
     *
     * Short options that expect value(s) will get them from the next
     * arguments. The first value can also be typed right after the option
     * without a space. Options can also be joined in conglomerates. Options
     * that expect a value should be at the end of a conglomerate, since the
     * rest of the argument will be evaluated as the option's value.
     *
     * Examples:
     *     program -q
     *     program -d something
     *     program -dsomething
     *     program -vvf arg_to_f
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _process_short_options($argument,
                                            &$rargs,
                                            &$values)
    {
        $characters = preg_split(
            '//', substr($argument, 1), -1, PREG_SPLIT_NO_EMPTY
        );
        $i = 1;
        $stop = false;

        foreach($characters as $ch) {
            $opt_string = "-". $ch;
            $i++; // an option was consumed

            $option = $this->_get_known_option($opt_string);

            if ( $option->nargs >= 1) {
                // The option expects values, insert the rest of $argument as
                // another argument (in rargs), if there is anything.
                if ( $i < strlen($argument) ) {
                    array_unshift($rargs, substr($argument, $i) );
                }
                // ... and stop iterating.
                $stop = true;
            }

            $this->_process_option($option, $rargs, $opt_string, $values);

            if ($stop) {
                break;
            }
        }
    }

    /**
     * Ask an option to process information.
     *
     * Process an option. If it throws an OptionValueError, exit with an error
     * message.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _process_option(&$option, &$rargs,
                                     $opt_string, &$values) {
        $nbvals = $option->nargs;

        if ( $nbvals < 1 ) {
            $value = $option->default;
        }
        else {
            $value = array();
        }

        // Not enough values given
        if ( count($rargs) < $nbvals ) {
            $vals = array("option" => $opt_string);
            if ( $nbvals == 1) {
                $what = "an argument";
            }
            else {
                $vals["nbargs"] = $nbvals;
                $what = "%(nbargs)s arguments";
            }
            $msg = _translate("%(option)s option takes $what.", $vals);

            $this->error($msg, WRONG_VALUE_COUNT_ERROR);
        }

        while ( $nbvals ) {
            $value[] = array_shift($rargs);
            $nbvals--;
        }

        // If only one value, set it directly as the value (not in an array)
        if ( $option->nargs == 1 ) {
            $value = $value[0];
            // Treat the option as the same one until another dash (-) is found to specify we're looking at a new one.
            while (is_array($rargs) && isset($rargs[0]) && is_string($rargs[0]) && $rargs[0][0] !== '-') {
                $value .= ' '.array_shift($rargs);
            }
        }

        try {
            $option->process($value, $opt_string, $values, $this);
        }
        catch (OptionValueError $exc) {
            $this->error(
                $exc->getMessage(),
                OPTION_VALUE_ERROR
            );
        }
    }

    /**
     * Find an option with the text from command line.
     *
     * If the option cannot be found, exit with an error.
     *
     * @return Option object
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _get_known_option($opt_text) {
        $option = $this->get_option($opt_text);

        // Unknown option. Exit with an error
        if ($option === Null) {
            $vals = array("option" => $opt_text);
            $msg = _translate("No such option: %(option)s", $vals);

            $this->error($msg, NO_SUCH_OPT_ERROR);
        }

        return $option;
    }

    /**
     * Resolve option conflicts intelligently.
     *
     * This method is the resolver for option conflict_handler="resolve". It
     * tries to resolve conflicts automatically. It disables an option string
     * so that the last option added that uses this string has precedence.
     *
     * If an option sees its last string disabled, it is removed entirely.
     * Options that get removed cannot be automatically re-enabled later.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _resolve_option_conflict(&$old_option,
                                             $option_text,
                                             &$parser)
    {
        if ( count($old_option->option_strings) == 1 ) {
            $parser->remove_option($option_text);
            return;
        }

        $old_option->disable_string($option_text);
    }

    /**
     * Re-enable an option string.
     *
     * When the conflict handler is set to "resolve", some strings may be
     * disabled. This method tries to re-enable a string.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _reenable_option_strings($option_strings) {
        $options = array_reverse($this->option_list);

        foreach ($option_strings as $option_text) {

            foreach ($options as $option) {
                $index = array_search($option_text, $option->disabled_strings);

                if ($index !== false) {
                    $option->option_strings[] = $option_text;
                    unset( $option->disabled_strings[$index] );
                    break;
                }
            }
        }
    }
}

function OptionContainer_format_option_help($container, $formatter) {
    if (empty($container->option_list) ) {
        return "";
    }

    $result = "";
    foreach ($container->option_list as $option) {
        if ($option->help !== SUPPRESS_HELP) {
            $result .= $formatter->format_option($option);
        }
    }

    return $result;
}

/**
 * Object returned by parse_args.
 *
 * It contains two attributes: one for the options and one for the positional
 * arguments.
 **/
class Values {
    function Values($options, $positional) {
        $this->options = $options;
        $this->positional = $positional;
    }
}

/**
 * Class representing an option.
 *
 * The option parser uses this class to represent options that are added to it.
 **/
class Option {

    /**
     * Set of possible types for options.
     **/
    protected $TYPES = array(
        "string",
        "int",
        "long",
        "float",
        "choice"
    );

    /**
     * Set of actions which may consume an argument for type.
     **/
    protected $TYPED_ACTIONS = array(
        "store",
        "append",
        "callback"
    );

    /**
     * Set of actions which require the type to be specified as an argument.
     **/
    protected $ALWAYS_TYPED_ACTIONS = array(
        "store",
        "append"
    );

    /**
     * Those actions use a constant to store information. They should be paired
     * with a "const" argument to the Option constructor.
     **/
    protected $CONST_ACTIONS = array(
        "store_const",
        "append_const"
    );

    function Option($settings) {
        $option_strings = array();

        // Get all option strings. They should be added without key in settings
        $i = 0;
        $longest_name = "";
        while ( $option_name = _array_pop_elem($settings, "$i") ) {
            $option_strings[] = $option_name;

            // Get the name without leading dashes
            if ($option_name[1] == "-") {
                $name = substr($option_name, 2);
            }
            else {
                $name = substr($option_name, 1);
            }

            // Keep only the longest name for default dest
            if ( strlen($name) > strlen($longest_name) ) {
                $longest_name = $name;
            }

            $i++;
        }

        if ( empty($option_strings) ) {
            $msg = _translate(
                "An option must have at least one string representation"
            );
            throw new InvalidArgumentException($msg);
        }

        $this->disabled_strings = array();
        $this->option_strings = $option_strings;

        $this->type = _array_pop_elem($settings, "type", Null);

        $this->choices = _array_pop_elem($settings, "choices", Null);

        // Default values that may be overridden by sensible action defaults or
        // by settings
        $this->dest = $longest_name;
        $this->nargs = 1;
        $this->default = NO_DEFAULT;

        // Set some sensible defaults depending on the chosen action
        $this->action = _array_pop_elem($settings, "action", "store");
        $this->_set_defaults_by_action($this->action);

        // Get default value
        //
        // Using this can lead to results that are unexpected.
        // Use OptionParser.set_defaults instead
        $this->default = _array_pop_elem($settings, "default", $this->default);

        // Other option settings
        $this->help = _array_pop_elem($settings, "help", "");
        $this->callback = _array_pop_elem($settings, "callback");
        $this->callback_args = _array_pop_elem($settings, "callback_args", Null);
        $this->_add_kwargs(
            _array_pop_elem($settings, "callback_kwargs", Null)
        );
        $this->const = _array_pop_elem($settings, "const", NO_DEFAULT);

        // Destination and metavar
        $this->dest = _array_pop_elem($settings, "dest", $this->dest);
        $this->metavar = _array_pop_elem(
            $settings,
            "metavar",
            strtoupper($this->dest)
        );

        $this->nargs = _array_pop_elem($settings, "nargs", $this->nargs);
        if ($this->nargs < 0) {
            $msg = _translate("nargs setting to Option cannot be negative");
            throw new InvalidArgumentException($msg);
        }

        // Yell if any superfluous arguments are given.
        if ( ! empty($settings) ) {
            throw new OptionError($settings);
        }

        // Make sure all the relevant information was given
        $this->_verify_settings_dependencies($settings);
    }

    /**
     * Process the option.
     *
     * When used, the option must be processed. Convert value to the right
     * type. Call the callback, if needed.
     *
     * Callback functions should have the following signature:
     *     function x_callback(&$option, $opt_string, $value,
     *                         &$parser, $callback_args) { }
     *
     * The name of the callback function is of no importance as long as it can
     * be called with a PHP dynamic evaluation (e.g. by doing $func="foo";
     * $func(...); ). The first and last arguments should be passed by
     * reference so that doing anything to them is not done to a copy of the
     * object only.
     *
     * @return void
     * @throws RuntimeException if an unknown action was requested
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function process($value, $opt_string, &$values, &$parser) {
        $this->convert_value($value, $opt_string);

        $this->take_action(
            $this->action, $this->dest,
            $value, $opt_string, $values, $parser
        );
    }

    /**
     * Convert value to the requested type.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function convert_value(&$value, $opt_string) {
        if (is_array($value) ) {
            foreach ($value as $val) {
                $this->_check_choice($val, $opt_string);
                $this->_check_builtin($val, $opt_string);
            }

            return;
        }
        else {
            $this->_check_choice($value, $opt_string);
            $this->_check_builtin($value, $opt_string);
        }
    }

    /**
     * Check that a value is one of the specified choices.
     *
     * If the value is not a correct choice, raise an OptionValueError
     * exception.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _check_choice($value, $opt_string) {
        if ($this->choices !== Null && ! in_array($value, $this->choices) ) {
            $vals = array(
                "opt" => $opt_string,
                "val" => $value,
                "choices" => join(",", $this->choices)
            );
            $msg = _translate(
                "option %(opt)s: invalid choice: %(val)s (choose from %(choices)s)",
                $vals
            );
            throw new OptionValueError($msg);
        }
    }

    /**
     * Convert a value to a builtin type.
     *
     * If the conversion fails, raise an OptionValueError exception.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _check_builtin(&$value, $opt_string) {
        $error = false;

        $orig_value = $value;

        switch($this->type) {
        case "int":
        case "long":
            $value = intval($value);
            $error = $orig_value != strval($value);
            break;
        case "float":
            $error = ! is_numeric($value);
            $value = floatval($value);
            break;
        }

        if ($error) {
            $vals = array(
                "opt" => $opt_string,
                "type" => $this->type,
                "val" => $orig_value
            );
            $msg = _translate(
                "option %(opt)s: invalid %(type)s value: %(val)s",
                $vals
            );
            throw new OptionValueError($msg);
        }
    }

    /**
     * Based on the requested action, do the right thing.
     *
     * @return void
     * @throws RuntimeException if an unknown action was requested
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function take_action($action, $dest,
                                $value, $opt_string, &$values, &$parser) {
        switch ($action) {
        case "store":
            $values[$dest] = $value;
            break;
        case "store_const":
            $values[$dest] = $this->const;
            break;
        case "store_true":
            $values[$dest] = true;
            break;
        case "store_false":
            $values[$dest] = false;
            break;
        case "append":
            if ( ! is_array($values[$dest]) ) {
                $values[$dest] = array();
            }
            array_push($values[$dest], $value);
            break;
        case "append_const":
            if ( ! is_array($values[$dest]) ) {
                $values[$dest] = array();
            }
            array_push($values[$dest], $this->const);
            break;
        case "count":
            if ( ! is_int($values[$dest]) ) {
                $values[$dest] = 0;
            }
            $values[$dest] += 1;
            break;
        case "callback":
            if ($this->callback !== Null) {
                $callback = $this->callback;
                $value = $callback($this, $opt_string, $value,
                                   $parser, $this->callback_args);
            }
            break;
        case "help":
            $parser->print_help();
            exit(0);
            break;
        case "version":
            $parser->print_version();
            exit(0);
            break;
        default:
            $vals = array("action" => $action);
            $msg = _translate("unknown action %(action)s", $vals);
            throw new RuntimeException($msg);
        }

    }

    /**
     * Disable an option string (e.g. --option) from the option.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function disable_string($opt_text) {
        $index = array_search($opt_text, $this->option_strings);

        if ( $index === false ) {
            $vals = array("opt" => $opt_text);
            $msg = _translate(
                "String \"%(opt)s\" is not part of the Option.",
                $vals
            );

            throw new InvalidArgumentException($msg);
        }

        $this->disabled_strings[] = $this->option_strings[$index];
        unset( $this->option_strings[$index] );
    }

    /**
     * Update callback_args with callback_kwargs.
     *
     * Values already defined in callback_args will get overridden
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _add_kwargs($kwargs) {
        if ($kwargs === Null) {
            return;
        }

        if ( ! is_array($kwargs) ) {
            $vals = array("args" => $kwargs);
            $msg = _translate(
                "'callback_kwargs', if supplied must be an array. not: %(args)s",
                $vals
            );
            throw new OptionError($msg);
        }

        if ($this->action !== "callback") {
            $msg = _translate(
                "'callback_kwargs' supplied for non-callback option"
            );
            throw new OptionError($msg);
        }

        $this->callback_args = array_merge($this->callback_args, $kwargs);
    }

    /**
     * Set some sensible default values depending on the action that was chosen.
     *
     * Some actions don't require one or another attribute. Set those to
     * sensible defaults in order to have everything behave correctly.
     *
     * Values set here can be overridden by settings passed to the Option's
     * constructor.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _set_defaults_by_action($action) {
        if ($this->type === Null) {
            if (in_array($action, $this->ALWAYS_TYPED_ACTIONS) ) {
                if ($this->choices !== Null) {
                    // The "choices" attribute implies type "choice"
                    $this->type = "choice";
                }
                else {
                    // No type? "string" is probably what you want
                    $this->type = "string";
                }
            }
        }

        switch ($action) {
        case "store_true":
            $this->default = false;
            break;
        case "store_false":
            $this->default = true;
            break;
        case "append":
        case "append_const":
            $this->default = array();
            break;
        case "count":
            $this->default = 0;
            break;
        case "callback":
        case "help":
        case "version":
            $this->dest = null;
            break;
        }

        if (! in_array($action, $this->TYPED_ACTIONS) ) {
            $this->nargs = 0;
        }
    }

    /**
     * Verify that actions are used with the right arguments.
     *
     * Some actions require the presence of other arguments to the Option
     * constructor. For example, actions that store a value should always be
     * used with a destination. Verify those dependencies and throw an
     * exception if things are not right.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _verify_settings_dependencies() {
        if ( ! in_array($this->action, $this->CONST_ACTIONS) ) {
            if ( $this->const !== NO_DEFAULT ) {
                $vals = array("action" => $this->action);
                $msg = _translate(
                    "'const' must not be supplied for action %(action)s",
                    $vals
                );
                throw new OptionError($msg);
            }
        }

        if ($this->type !== Null) {
            if (! in_array($this->type, $this->TYPES) ) {
                $vals = array("type" => $this->type);
                $msg = _translate(
                    "invalid option type: %(type)s",
                    $vals
                );
                throw new OptionError($msg);
            }

            if (! in_array($this->action, $this->TYPED_ACTIONS) ) {
                $vals = array("action" => $this->action);
                $msg = _translate(
                    "must not supply a type for action %(action)s",
                    $vals
                );
                throw new OptionError($msg);
            }
        }

        if ($this->type == "choice") {
            if ($this->choices === Null) {
                $msg = _translate(
                    "must supply a list of choices for type 'choice'"
                );
                throw new OptionError($msg);
            }
            else if (! is_array($this->choices) ) {
                $vals = array("ch" => gettype($this->choices) );
                $msg = _translate(
                    "choices must be a list of strings ('%(ch)s' supplied)",
                    $vals
                );
                throw new OptionError($msg);
            }
        }
        else if ($this->choices !== Null) {
            $vals = array("type" => $this->type);
            $msg = _translate(
                "must not supply choices for type %(type)s",
                $vals
            );
            throw new OptionError($msg);
        }

        if ( in_array($this->action, $this->TYPED_ACTIONS) ) {
            // Set a sensible default of 1 argument for typed actions
            if ($this->nargs === Null) {
                $this->nargs = 1;
            }
        }
        else {
            if (! $this->nargs === Null) {
                //XXX: ??
            }
        }

        if ($this->action == "callback") {
            if ($this->callback === Null) {
                $msg = _translate(
                    "'callback' must be supplied for action callback"
                );
                throw new OptionError($msg);
            }
            else if ( ! function_exists($this->callback) ) {
                $vals = array("function" => $this->callback);
                $msg = _translate(
                    "callback not callable: %(function)s",
                    $vals
                );
                throw new OptionError($msg);
            }

            if ( $this->callback_args !== Null &&
                 ! is_array($this->callback_args) )
            {
                $vals = array("args" => $this->callback_args);
                $msg = _translate(
                    "'callback_args', if supplied must be an array. not: %(args)s",
                    $vals
                );
                throw new OptionError($msg);
            }
        }
        else {
            if ($this->callback !== Null) {
                $vals = array("function" => $this->callback);
                $msg = _translate(
                    "'callback' supplied (%(function)s) for non-callback action",
                    $vals
                );
                throw new OptionError($msg);
            }

            if ($this->callback_args !== Null) {
                $msg = _translate(
                    "'callback_args' supplied for non-callback action"
                );
                throw new OptionError($msg);
            }
        }
    }

    /**
     * String representation for PHP5
     *
     * This is a wrapper for automatically displaying the option in PHP5 with
     * the option strings and the description when it is printed out.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function __toString() {
        return $this->__str__();
    }

    /**
     * String representation of the option.
     *
     * Format a string with option name and description so that it can be used
     * for a help message.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function __str__() {
        //XXX this must go out into the formatter?
        $call_method = "";
        foreach ($this->option_strings as $name) {
            //FIXME metavar must be shown only when needed.
            $call_method .= $name. " ". $this->metavar. " ";
        }

        return $call_method. " ". _translate($this->help);
    }

    /**
     * Return a hash string that identifies this object.
     **/
    public function __hash__() {
        return sha1( serialize($this) );
    }
}

class IndentedHelpFormatter {
    public function IndentedHelpFormatter($indent_increment=2,
                                          $max_help_position=24,
                                          $width=Null,
                                          $short_first=true) {
        $this->option_strings = array();
        $this->current_indent = 0;

        $this->indent_increment = $indent_increment;
        if (! $width) {
            // $size = $this->terminal_size();
            // $width = $size[0];
            $width = 80;
        }
        $this->width = $width;

        $this->max_help_position = $max_help_position;
    }

    public function terminal_size() {
        if (PHP_OS != "Linux")
            return array(80,24);

        $dims = explode(" ", shell_exec("stty size"));
        array_map("intval", $dims);
        return array_reverse($dims);
    }

    /**
     * Increase indentation.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function indent() {
        $this->current_indent += $this->indent_increment;
    }

    /**
     * Decrease indentation.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function dedent() {
        $this->current_indent -= $this->indent_increment;
        assert($this->current_indent >= 0 );
    }

    /**
     * Format the description string.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_description($description) {
        $result = "";
        if ($description) {
            $result .= $this->_format_text($description). "\n";
        }
        return $result;
    }

    /**
     * Format the usage string.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_usage($usage) {
        return "Usage: ". $usage. "\n";
    }

    /**
     * Format the epilog string.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_epilog($epilog) {
        $result = "\n";
        if ($epilog) {
            $result .= $this->_format_text($epilog). "\n\n";
        }
        return $result;
    }

    /**
     * Format the heading of a section.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_heading($text) {
        $indent = str_repeat(" ", $this->current_indent);
        return $indent. $text. ":\n";
    }

    /**
     * Format a string for an option.
     *
     * The help for each option consists of two parts:
     *   * the opt strings and metavars
     *     eg. ("-x", or "-fFILENAME, --file=FILENAME")
     *   * the user-supplied help string
     *     eg. ("turn on expert mode", "read data from FILENAME")
     *
     * If possible, we write both of these on the same line:
     *   -x      turn on expert mode
     *
     * But if the opt string list is too long, we put the help
     * string on a second line, indented to the same column it would
     * start in if it fit on the first line.
     *   -fFILENAME, --file=FILENAME
     *           read data from FILENAME
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function format_option($option) {
        $result = array();

        $opts = $this->option_strings[$option->__hash__()];
        $opt_width = $this->help_position - $this->current_indent - 2;
        if (strlen($opts) > $opt_width)
        {
            $opts = str_pad("", $this->current_indent) . $opts . "\n";
            $indent_first = $this->help_position;
        }
        else
        {
            $opts = str_pad("", $this->current_indent) . str_pad($opts, $opt_width + 2);
            $indent_first = 0;
        }
        $result[] = $opts;

        if ($option->help) {
            // $help_text = $this->expand_default($option);
            $help_text = $option->help;
            $help_lines = explode("\n", wordwrap($help_text, $this->help_width));
            $result[] = str_pad("", $indent_first) . $help_lines[0] . "\n";
            for ($i = 1; $i < count($help_lines); $i += 1)
                $result[] = str_pad("", $this->help_position) . $help_lines[$i] . "\n";
        }
        else if (substr($opts, -1) != "\n") {
            $result[] = "\n";
        }
        return implode("", $result);
    }

    /**
     * Pre-format option strings and determine positions for help strings.
     *
     * @return void
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    public function store_option_strings($parser) {
        $this->indent();
        $max_len = 0;
        foreach ($parser->option_list as $opt) {
            $strings = $this->format_option_strings($opt);
            $this->option_strings[$opt->__hash__()] = $strings;
            $max_len = max($max_len, strlen($strings) + $this->current_indent);
        }
        $this->dedent();
        $this->help_position = min($max_len + 2, $this->max_help_position);
        $this->help_width = $this->width - $this->help_position;
    }

    /**
     * Return a comma-separated list of option strings & metavariables.
     **/
    public function format_option_strings($option) {
        $option_strings = array();
        $metavar = $option->nargs > 0 ? $option->metavar : "";

        foreach ($option->option_strings as $name) {
            if (strlen($name) == 2)
              $option_strings[] = $name . $metavar;
            else if ($option->nargs > 0)
              $option_strings[] = $name . "=" . $metavar;
            else
              $option_strings[] = $name;
        }
        return implode(", ", $option_strings);
    }

    /**
     * Format text in a paragraph.
     *
     * @return string
     * @author Gabriel Filion <lelutin@gmail.com>
     **/
    private function _format_text($text) {
        return wordwrap($text, $this->width - $this->current_indent);
        return $text;
    }
}

/**
 * Exception on duplicate options
 *
 * Exception raised when an option added tries to use a string representation
 * (e.g. "--option") that is already used by a previously added option.
 **/
class OptionConflictError extends Exception {
    function OptionConflictError($name) {
        $msg = _translate("Duplicate definition of option \"$name\"");
        parent::__construct($msg);
    }
}

/**
 * Exception on superfluous or conflicting arguments
 *
 * Raised when unknown options are passed to Option's constructor or
 * when conflitcting settings are passed to the Option constructor.
 **/
class OptionError extends Exception {
    function OptionError($arguments) {
        if ( is_array($arguments) ) {
            $msg = $this->unknown_settings($arguments);
        }
        else {
            $msg = $arguments;
        }

        parent::__construct($msg);
    }

    function unknown_settings($arguments) {
        $args_as_string = implode(", ", array_keys($arguments) );

        return _translate(
            "The following settings are unknown: $args_as_string"
        );
    }
}

/**
 * Exception on incorrect value for an option
 *
 * This exception should be raised by callback functions if there is an error
 * with the value that was passed to the option. optparses catches this and
 * exits the program, after printing the message in the exception to stderr.
 **/
class OptionValueError extends Exception { }

} // __OPTPARSE_PHP
