<?php namespace App\Exceptions;

class PaasException extends \Exception {
	
	protected $previous = 'PaaS-api service exception';
	
}