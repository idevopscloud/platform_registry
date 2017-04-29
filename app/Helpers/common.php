<?php

use Illuminate\Support\Facades\Log;
use App\Exceptions\ApiException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Exceptions\CdException;
use Symfony\Component\Debug\header;
use App\Exceptions\PaasException;

/**
 * generator uuid
 * 
 * @return string
 */
function gen_uuid() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}

/**
 * request url
 *
 * @param unknown $url        	
 * @param unknown $method        	
 * @param unknown $parameters        	
 * @param string $headers        	
 * @return multitype:mixed unknown
 */
function do_request($url, $method, $parameters = [], $headers = null) {
	$response = request_api ( $url, $method, $parameters, $headers );
	if ($response ['code'] != 200) {
		throw new \Exception ( $response ['content'] ?  : trans ( "exception.response_error", [ 
				'code' => $response ['code'],
				'error' => $response ['error'] 
		] ), $response ['code'] );
	}
	
	$content = json_decode ( $response ['content'], true );
	if (isset ( $content ['code'] ) && $content ['code'] != 0) {
		$message = isset ( $content ['msg'] ) ? $content ['msg'] : trans("exception.api_response_error");
		throw new ApiException ( $message, $content['code'] );
	}
	$data = isset ( $content ['data'] ) ? $content ['data'] : $content;
	return $data;
}

function do_request_paas($url, $method, $parameters = [],$headers = null) {
	$response = request_api($url, $method, $parameters, $headers);
	if ($response ['code'] != 200) {
		throw new PaasException ( $response ['content'] ?  : trans ( "exception.response_error", [ 
				'code' => $response ['code'],
				'error' => $response ['error'] 
		] ), $response ['code'] );
	}
	$content = json_decode($response['content'], true);
	
	$data = isset($content['data']) ? $content['data'] : $content;
	return $data;
}

function do_request_cd($url, $method, $parameters = [],$headers = null, $user_pwd =null, $response_header = false) {
	$response = request_api($url, $method, $parameters, $headers, $user_pwd, $response_header);
	if ($response['code'] < 200 || $response['code'] > 300) {
		throw new CdException($response['content'], $response['code']);
	}
	return $response;
}

function request_api($url, $method, $parameters = [], $headers = null, $user_pwd = null, $response_header = false){
	Log::debug('[Request Third App] URL : ' . $url . ' Method: ' . $method, $parameters);
	if ($headers === null) {
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);
	}

	$data = json_encode($parameters);
	$handle = curl_init();
	switch($method) {
		case 'GET':
			if (strpos($url, '?') === false)
				$url = $url . '?' . http_build_query ( $parameters );
			else if (!empty($parameters))
				$url .= '&'.http_build_query ( $parameters );
			break;
		case 'POST':
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'PUT':
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'PATCH':
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PATCH');
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'DELETE':
			if (strpos($url, '?') === false)
				$url = $url . '?' . http_build_query ( $parameters );
			else if (!empty($parameters))
				$url .= '&'.http_build_query ( $parameters );
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;
	}
	curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
	$user_pwd && curl_setopt($handle, CURLOPT_USERPWD, $user_pwd);
	curl_setopt($handle, CURLOPT_URL, $url);
	$headers && curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT ,0);
	curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true); // 支持跳转
	curl_setopt($handle, CURLOPT_TIMEOUT, 120);
	
	$header = []; // response header
	if (!$response_header) {
		$content = curl_exec($handle);
	} else {
		curl_setopt($handle, CURLOPT_HEADER, 1);
		$response = curl_exec($handle);
		$header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$header_string = substr($response, 0, $header_size);
		$content = substr($response, $header_size);
		preg_match_all('/x-text-size: (\d*)/', $header_string, $matches);
		if (isset($matches[1][0])) {
			$header['x-text-size'] = $matches[1][0];
			$header['Access-Control-Expose-Headers'] = 'x-text-size';
		}
	}
	
	$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
	$error = curl_error($handle);
	
	curl_close($handle);
	
    return ['code'=>$code, 'content'=>$content, 'error'=>$error, 'header'=>$header];	
}

function arrayRecursiveDiff($aArray1, $aArray2) {
	$aReturn = array();

	foreach ($aArray1 as $mKey => $mValue) {
		if (array_key_exists($mKey, $aArray2)) {
			if (is_array($mValue)) {
				$aRecursiveDiff = arrayRecursiveDiff($mValue, $aArray2[$mKey]);
				if (count($aRecursiveDiff)) { $aReturn[$mKey] = $aRecursiveDiff; }
			} else {
				if ($mValue != $aArray2[$mKey]) {
					$aReturn[$mKey] = $mValue;
				}
			}
		} else {
			$aReturn[$mKey] = $mValue;
		}
	}
	return $aReturn;
}

if (! function_exists('simple_decrypt')) {
	/**
	 * Returns decrypted original string
	 */
	function simple_decrypt($encrypted_string, $encryption_key) {
		$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$decrypted_string = mcrypt_decrypt(MCRYPT_BLOWFISH, $encryption_key, $encrypted_string, MCRYPT_MODE_ECB, $iv);
		return $decrypted_string;
	}
}