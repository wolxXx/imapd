<?php

namespace TheFox\Imap;

class Event{
	
	const TRIGGER_MAIL_ADD_PRE = 1000;
	const TRIGGER_MAIL_ADD = 1010;
	const TRIGGER_MAIL_ADD_POST = 1020;
	
	private $trigger = null;
	private $object = null;
	private $function = null;
	private $returnValue = null;
	
	public function __construct($trigger = null, $object = null, $function = null){
		$this->trigger = $trigger;
		$this->object = $object;
		$this->function = $function;
	}
	
	public function getTrigger(){
		return $this->trigger;
	}
	
	public function getReturnValue(){
		return $this->returnValue;
	}
	
	public function execute($args = array()){
		$object = $this->object;
		$function = $this->function;
		
		array_unshift($args, $this);
		
		if($object){
			$this->returnValue = call_user_func_array(array($object, $function), $args);
		}
		else{
			$this->returnValue = call_user_func_array($function, $args);
		}
		
		return $this->returnValue;
	}
	
}
