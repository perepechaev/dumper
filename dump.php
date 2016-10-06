<?php

$_REQUEST['dump'] = 1;
$_REQUEST['force_cli'] = isset($_REQUEST['force_cli']) ? $_REQUEST['force_cli'] : 0;
$_REQUEST['force_error_log'] = 0;
$_REQUEST['force_nocolor'] = isset($_REQUEST['force_nocolor']) ? $_REQUEST['force_nocolor'] : 0;

class DumpVariable
{
    private $wrapper;

    static $types = array();

    public function __construct($wrapper){
        $this->wrapper = $wrapper;
        self::init();
    }

    public function dump(){
        $row = dfl();

        $this->wrapper->definition($row);

        $file = 'empty';
        if (!empty($row['file'])){
			$file = file_get_contents($row['file']);
			$file = explode("\n", $file);
        }

		$line = $file[$row['line'] - 1];

        preg_match('/(dd|dm|dmq|dt|dc|dump|p|dq)\((.*)\);/', $line, $matches);
        $args = preg_replace('/\([^\)]+\)/', '(...)', $matches[2]);
        $names = explode(',', $args);
        $names = array_map('trim', $names);

        foreach (func_get_args() as $key => $var){
            foreach (self::$types as $type){
                $type->set($var);

                if ($type->capture()){
                    $this->wrapper->dump($type, $names[$key]);
                    break;
                }

            }
        }
        $this->wrapper->end();
    }

    public function backtrace(){
        $this->wrapper->backtrace();
    }

    static private function init(){
        if (self::$types){
            return;
        }

        self::$types = array(
            new DumpVariableExceptionType(),
            new DumpVariableActiveRecordType(),
            new DumpVariableActiveRecordListType(),
            new DumpVariableArrayType(),
            new DumpVariableClassType(),
            new DumpVariableObjectType(),
            new DumpVariableBooleanType(),
            new DumpVariableStringType(),
            new DumpVariableStandartType(),
            new DumpVariableVariable(),
        );
    }
}

class DumpVariableColor
{
    const RED = 1;
    const GREEN = 2;
    const BLUE = 3;
    const YELLOW = 3;
}

class DumpVariableVariable
{
    protected $variable;

    public function set($variable){
        $this->variable = $variable;
    }

    public function capture(){
        return false;
    }

    public function getType(){
        if (is_null($this->variable)){
            return '';
        }
        return ucfirst(gettype($this->variable));
    }

    public function getLength(){
        return '';
    }

    public function getContent(){
        return var_export($this->variable, true);
    }

    public function getColor(){
        return DumpVariableColor::YELLOW;
    }
}

class DumpVariableStandartType extends DumpVariableVariable
{
    public function capture(){
        return true;
    }
}

class DumpVariableBooleanType extends DumpVariableVariable
{
    public function capture(){
        return is_bool($this->variable);
    }

    public function getColor(){
        return $this->variable ? DumpVariableColor::GREEN : DumpVariableColor::RED;
    }
}


class DumpVariableStringType extends DumpVariableVariable
{
    public function set($variable){
        $variable = is_string($variable) ? str_replace("\t", "    ", $variable) : $variable;
        parent::set($variable);
    }

    public function capture(){
        return is_string($this->variable);
    }

    public function getLength(){
        return mb_strlen($this->variable);
    }

    public function getContent(){
        return $this->variable;
    }
}


class DumpVariableArrayType extends DumpVariableVariable
{
    public function capture(){
        return is_array($this->variable);
    }

    public function getLength(){
        return count($this->variable);
    }

    public function getContent(){
        return substr(print_r($this->variable, true), 5);
    }
}

class DumpVariableObjectType extends DumpVariableVariable
{
    public function capture(){
        return is_object($this->variable);
    }

    public function getType(){
        return 'class ' . get_class($this->variable);
    }

    public function getContent(){
        $content = print_r($this->variable, true);

        $content = explode("\n", $content);
        $content[0] = '';
        $content = implode("\n", $content);

        $content = str_replace("\t", "    ", $content);

        return $content;
    }
}

class DumpVariableActiveRecordType extends DumpVariableObjectType
{
    public function capture(){
        return is_object($this->variable) && $this->variable instanceof CModel;
    }

    public function getContent(){
        $content = print_r($this->variable->getAttributes(), true);

        $content = explode("\n", $content);
        $content[0] = '';
        $content = implode("\n", $content);

        return $content;
    }
}

class DumpVariableActiveRecordListType extends DumpVariableActiveRecordType
{
    public function capture(){
        if (is_array($this->variable) === false){
            return false;
        }

        $first = current($this->variable);

        return is_object($first) && $first instanceof CModel;
    }

    public function getType(){
        return get_class(current($this->variable));
    }

    public function getLength(){
        return count($this->variable);
    }

    public function getContent(){
        $array = $this->variable;

        $content = '';
        foreach ($array as $key => $item){
            $this->variable = $item;
            $content .= "\n";
            $content .= "#$key " . get_class($item) . " (";
            $content .= "\n";
            $content .= trim(parent::getContent(), "()\n");
            $content .= "\n)";
        }

        $this->variable = $array;

        return $content;
    }
}

class DumpVariableExceptionType extends DumpVariableObjectType
{
    public function capture(){
        return is_object($this->variable) && $this->variable instanceof Exception;
    }

    public function getType(){
        return get_class($this->variable) . ': ' . $this->variable->getMessage();
    }

    public function getContent(){
        return "\n" . $this->variable->getTraceAsString();
    }
}

class DumpVariableClassType extends DumpVariableVariable
{
    public function capture(){
        return is_object($this->variable) && $this->variable instanceof DumpClass;
    }

    public function getType(){
        return 'class';
    }

    public function getContent(){
        return $this->variable->getClassName();
    }
}


abstract class DumpVariableAbstractWrapper
{
    public function definition($row){
        printf("%s:%d\n", $row['file'], $row['line']);
    }

    public function dump(DumpVariableVariable $variable){
        printf("%s(%s)\t%s\n", $variable->getType(), $variable->getLength(), $variable->getContent());
    }

    public function end(){
        echo "\n";
    }

    public function backtrace(){
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $cnt = count($trace);
        $dgt = strlen($cnt);

        $i = 0;
        foreach ($trace as $row){

            if (isset($row['file']) && $row['file'] === __FILE__){
                continue;
            }
            $class = isset($row['class']) ? $row['class'] . ($row['type'] ?: '->') : '';

            $called = empty($row['file']) ? '' : sprintf(" called at [%s:%d]", $row['file'], $row['line']);
            printf("#%-{$dgt}d %s%s()%s\n", $i++, $class, $row['function'], $called);
        }
    }
}

class DumpVariableHtmlWrapper extends DumpVariableAbstractWrapper
{
    public function definition($row){

        $title = sprintf('%s:%d', $row['file'] , $row['line']);
        printf('<div style="color: green">DUMP<br/>%s</div>', $title);
        echo "<table>";
    }

    public function dump(DumpVariableVariable $variable, $name){
        printf('<tr><td style="color:gray; background-color: #DDD;">%s</td><td>(%s)[%d]</td><td style="border: 1px solid gray;"><pre>%s</pre></td></tr>', htmlspecialchars($name), $variable->getType(), $variable->getLength(), htmlspecialchars($variable->getContent()));
    }

    public function end(){
        echo "</table>";
    }

    public function backtrace(){
		echo "<pre>";
        parent::backtrace();
		echo "</pre>";
    }
}

class DumpVariableCliWrapper extends DumpVariableAbstractWrapper
{
}

class DumpVariableColoredCliWrapper extends DumpVariableAbstractWrapper
{
    private $width;

    public function __construct(){
        $this->width = trim(`tput cols`);
        //$size = trim(`stty size`);
        // sscanf($size, '%d %d', $height, $this->width);

        // if (empty($this->width)){
            // $this->width = 120;
        // }
    }

    public function definition($row){

        $row['file'] = str_replace(trim(`pwd`) . '/', '', $row['file']);

        $title = sprintf('%s:%d', $row['file'] , $row['line']);
        printf("\033[1;31;47m DUMP%s\033[0m\n", str_pad('', $this->width - 5, ' '));
        printf("\033[0;32;47m %s\033[0m\n", str_pad($title, $this->width - 1, ' '));
    }

    public function dump(DumpVariableVariable $variable, $name){

        $name = sprintf("%-15s", $name);
        $type = sprintf("%-6s", $variable->getType());

        $definition = sprintf("\033[1;35;40m%s\033[0;36;40m = \033[36;40m%s", $name, $type);
        $length = $variable->getLength();
        $definition .= "\033[0;37;40m";
        if (is_int($length)){
            $definition .= sprintf(" (%d)", $length);
        }
        $definition .= "\033[0;37;40m";
        switch ($variable->getColor()){
            case DumpVariableColor::RED:
                $definition .= "\033[31m";
                break;
            case DumpVariableColor::YELLOW:
                $definition .= "\033[33m";
                break;
            case DumpVariableColor::GREEN:
                $definition .= "\033[32m";
                break;
            default:
                $definition .= "\033[38m";
        }

        printf("%s ", $definition);
        $content = $variable->getContent();
        $content = explode("\n", $content);

        echo iconv('CP1251', 'UTF-8', sprintf("%s", iconv('UTF-8', 'CP1251', $content[0])));

        $line_length = mb_strlen($name, 'UTF-8') + strlen($type) + strlen($length) + mb_strlen($content[0], 'UTF-8') + 4 + (is_int($length) ? 3 : 0);
        $line_length = $line_length % $this->width;
        echo str_pad('', $this->width - $line_length, ' ');

        for ($i = 1; $i < count($content); $i++){
            printf("\n\033[0;33;40m%s", $content[$i]);
            echo str_pad('', $this->width - mb_strlen($content[$i], 'UTF-8') % $this->width, ' ');

        }

        echo "\033[0m\n";
    }
}

class DumpClass
{
    private $className = '';

    public function __construct($object){
        if (is_object($object) === false){
            $this->className = gettype($object);
        }
        else {
            $this->className = get_class($object);
        }
    }

    public function getClassName(){
        return $this->className;
    }
}

if (_is_html()){
	$wrapper = new DumpVariableHtmlWrapper();
}
elseif (_is_colored()){

	$wrapper = new DumpVariableColoredCliWrapper();
}
else {
	$wrapper = new DumpVariableCliWrapper();
}



global $__dump;
$__dump  = new DumpVariable($wrapper);

function dd(){
    if (empty($_REQUEST['dump'])){
        return;
    }

    global $__dump;

    /* clear buffer
    while (@ob_end_clean());
    */
	$args = func_get_args();
    //call_user_func_array('dump', $args);
    call_user_func_array(array($__dump, 'dump'), $args);
    die;
}

function dc(){
    if (empty($_REQUEST['dump'])){
        return;
    }

    global $__dump;

	$args = func_get_args();
    foreach ($args as & $object){
        $object = new DumpClass($object);
    }
    //call_user_func_array('dump', $args);
    call_user_func_array(array($__dump, 'dump'), $args);
    die;
}

function dq(CActiveRecord $model){
    $sql = Yii::app()->db->getCommandBuilder()->createFindCommand($model->tableName(), $model->getDbCriteria())->getText();

    $params = $model->getDbCriteria()->params;

    $sql = str_replace(array_keys($params), array_values($params), $sql);
    call_user_func_array('dump', array($sql));
    die;
}

function dmq(CActiveRecord $model){
    $sql = Yii::app()->db->getCommandBuilder()->createFindCommand($model->tableName(), $model->getDbCriteria())->getText();

    $params = $model->getDbCriteria()->params;

    $sql = str_replace(array_keys($params), array_values($params), $sql);
    dm($sql);
}

function dump_logs($count = 1){
    $logs = Yii::getLogger()->getLogs();
    while( $count-- > 0){
        call_user_func_array('dump', array_pop($logs));
    }
}

function dobject($object){


    $classname = get_class($object);
    if (_is_html()) {
        $txt = '<h3>Object: <span style="color:blue">' . $classname . '</h3>';
    }
    elseif (_is_colored()){
        $txt = sprintf("\033[1;34mObject: \033[0;37m%s\033[0m\n", $classname);
    }
    else {
        $txt = 'Object: ' . $classname . "\n";
    }

    _dump_display($txt);
}

function dump()
{
    global $__dump;
    call_user_func_array(array($__dump, 'dump'), func_get_args());
}

function dt(){
    global $__dump;
    $__dump->backtrace();
}

function dfl(){
    $log = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    foreach ($log as $row){
        if (empty($row['file'])){
            continue;
        }
        if ($row['file'] === __FILE__){
            continue;
        }

        return $row;
    }
}

function _dump_variable($var) {
    _dump_display($var);
}

function _dump_display($var){
    $content = '';

    if (is_object($var)){
        if ($var instanceof CModel){

            $content = print_r($var->getAttributes(), true);
        }
        else {
            $content = print_r($var, true);
        }
    }
    elseif (is_array($var)){
        if (reset($var) instanceof CModel){
            foreach($var as $model){
                $content .= print_r($model->getAttributes(), true);
            }
        }
        else {
            $content = print_r($var, true);
        }
    }
    else {
        $content = var_export($var, true);
    }

    _dump_colored($content);
}

function _dump_colored($content){
    if (_is_html()){
        // TODO
    }
    elseif (_is_colored()){
        printf("\033[0;33m%s\033[0m\n", $content);
    }
    else {
        echo $content . "\n";
    }
}


function print_dfl($row){
    if (_is_html()){
        printf('<h3 style="color: green">%s:%d</h3>', $row['file'], $row['line']);
    }
    elseif (_is_colored()){

        //echo "\n";
    }
    else {
        printf("%s:%d\n", $row['file'], $row['line']);
    }
}

function _is_html(){
    if (php_sapi_name() === 'cli'){
        return false;
    }

    if (empty($_REQUEST['force_cli']) === false && $_REQUEST['force_cli'] == 1){
        return false;
    }

    return true;
}

function _is_colored(){
    if (php_sapi_name() === 'cli'){
        return true;
    }
    return empty($_REQUEST['force_nocolor']) === false;
}

function dump_interface($class_name, $function_body_callback = null){
    $reflection = new ReflectionClass($class_name);
    $class = $reflection->isAbstract() ? 'abstract ' : '';
    $class .= 'class ' . $reflection->getName();
    $class .= $reflection->getParentClass() ? ' extends ' . $reflection->getParentClass()->getName() : '';
    $class .= $reflection->getInterfaceNames() ? ' implements ' . implode(', ', $reflection->getInterfaceNames()) : '';

    $body = '';
    foreach ($reflection->getConstants() as $key => $constant) {
        $body .= "    const " . $key . " = " . var_export($constant, true) . ";\n";
    }

    $body .= "\n";

    foreach ($reflection->getProperties() as $name => $property){
        /* @var ReflectionProperty $property */
        $property instanceof ReflectionProperty;
        if ($property->getDeclaringClass()->getName() !== $reflection->getName()){
            continue;
        }
        $body_property = '';
        if ($property->getDocComment()){
            $body_property .= $property->getDocComment();
        }

        $body .= $body_property;
        $body .= '    ';
        if ($property->isStatic()){
            $body .= 'static ';
        }

        if ($property->isPrivate()){
            $body .= 'private ';
        }
        elseif ($property->isProtected()){
            $body .= 'protected ';
        }
        elseif ($property->isPublic()){
            $body .= 'public ';
        }

        $body .= '$' . $property->getName();
        //$body .= $property->getValue() ? ' = ' . var_export($property->getValue(), true) : '';
        $body .= ";\n";
    }

    $body .= "\n";

    foreach ($reflection->getMethods() as $method){
        /* @var ReflectionMethod $method */
        $method instanceof ReflectionMethod;

        if ($method->getDeclaringClass()->getName() !== $reflection->getName()){
            continue;
        }

        $body .= '    ';

        if ($method->isAbstract()){
            $body .= 'abstract ';
        }

        if ($method->isStatic()){
            $body .= 'static ';
        }

        if ($method->isPrivate()){
            $body .= 'private ';
        }
        elseif ($method->isProtected()){
            $body .= 'protected ';
        }
        elseif ($method->isPublic()){
            $body .= 'public ';
        }

        $body .= 'function ' . $method->getName();

        $parameters = array();
        foreach ($method->getParameters() as $parameter){
            /* @var ReflectionParameter $parameter */
            $parameter instanceof ReflectionParameter;
            $value = $parameter->isOptional() ? ' = ' . 'null' : '';
            $class_name = $parameter->getClass() ? $parameter->getClass()->getName() . ' ' : '';
            $parameters[] = $class_name . '$' . $parameter->getName() . $value;
        }
        $body .= sprintf("(%s)", implode(', ', $parameters));
        $body .= sprintf("{\n%s\n    }\n\n", $function_body_callback ? $function_body_callback($method) : null);
    }

    $body = sprintf("%s\n{\n%s\n}\n", $class, $body);
    //$body = str_replace("\n\n", "\n", $body);
    _dump_display(highlight_string("<?php \n\n" . $body . '<pre>', true));
    dd((string) $reflection);
}

function dm(){

    $dump = new DumpVariable( new DumpVariableHtmlWrapper() );

    ob_start();
    call_user_func_array(array($dump, 'dump'), func_get_args());
    $dump->backtrace();
    $content = ob_get_clean();

    if (isset($_SERVER['HTTP_HOST'])){
        $link = (!empty($_SERVER['HTTPS']) ? 'http://' : 'https://' ) .$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $link = sprintf('<p style="color: blue; font-size: small;">%s</p>', $link);
        $content = $link . $content;
    }

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=CP1251' . "\r\n";

    $row = dfl();

    mail('debug', $row['file'] . ":" . $row['line'], $content, $headers);
}

function dump_interface_parent_method(ReflectionMethod $method){
    $parameters = array();
    foreach ($method->getParameters() as $param) {
        $parameters[] = '$' . $param->getName();
    }
    return '        parent::' . $method->getName() . '(' . implode(', ', $parameters) . ");";
}

function print_dep($data, $max_dep = 4, $dep = 0){
    if ($max_dep < $dep){
        return;
    }
    foreach ($data as $key => $value){
        if (is_array($value)){
            echo str_pad(' ', 8*$dep). "[$key] => array(";
            if (!empty($value)){
                echo "\n";
                print_dep($value, $max_dep, $dep + 1);
                echo str_pad(' ', 8*$dep) . ");\n";
            }
            else {
                echo ");\n";
            }
        }
        elseif (is_object($value)){
            echo str_pad(' ', 8*$dep). "[$key] => object " . get_class($value) . "\n";
        }
        elseif (is_resource($value)){
            dd("RESOURCE");
        }
        else {
            echo str_pad(' ', 8*$dep). "[$key] => $value\n";
        }

        if (is_object($value)){
            print_dep((array) $value->toArray(), $max_dep, $dep + 1);
        }
    }
}

if (_is_profiling()){

	xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
	register_shutdown_function('xhprof_save');

}

function p(){
    return new ProfilerMethod();
}

class ProfilerMethod
{
    public function __construct(){
	    xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
    }

    public function __destruct(){
        xhprof_save();
    }
}

function _is_profiling(){
    if (isset($_SERVER['argv'])) foreach ($_SERVER['argv'] as $arg){
        list($profiler) = sscanf($arg, "--profiler=%s");

        if (filter_var($profiler, FILTER_VALIDATE_BOOLEAN)){
            return true;
        }
    }

    if (isset($_REQUEST['profiler']) && filter_var($_REQUEST['profiler'], FILTER_VALIDATE_BOOLEAN)){
        return true;
    }

    return false;
}


function xhprof_save(){
	$xhprof_data = xhprof_disable();

	$XHPROF_ROOT = "/usr/share/php";
	include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_lib.php";
	include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_runs.php";

	$xhprof_runs = new XHProfRuns_Default();
	$run_id = $xhprof_runs->save_run($xhprof_data, "xhprof_testing");

	$sLink = "http://xhprof.kreddy.topas/index.php?" . http_build_query(array(
		'run' => $run_id,
		'source' => 'xhprof_testing',
		'dir' => '/tmp/xhprof',
	));
	mail('profiler@localhost', '', sprintf('<a href="%1$s">%1$s</a>', $sLink));
}
