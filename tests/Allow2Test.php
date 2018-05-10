<?php 

/**
*  Corresponding Class to test Allow2 class
*
*  @author Allow2CEO
*/
class Allow2Test extends PHPUnit_Framework_TestCase{
	
  /**
  * Just check if the Allow2 class has no syntax error 
  *
  */
  public function testIsThereAnySyntaxError(){
	$var = new Allow2\Allow2;
	$this->assertTrue(is_object($var));
	unset($var);
  }
  
  /**
  * Example test
  *
  */
  public function testMethod1(){
	$var = new Allow2\Allow2;
	$this->assertTrue($var->method1("hey") == 'Hello World');
	unset($var);
  }
  
}
