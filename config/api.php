<?php
return [
		'idevops_host' => 'http://192.168.99.101:8080',
		'api_key' => 'APGH0cNRd7IC4JSLDn',
		'items' => [
				
				'getUserInfo' => [
						'path'=>'v1/user/mime',
						'method'=>'GET'
				],
				'getCompany' => [
						'path'=>'third/account/companies',
						'method'=>'GET',
						'type' => 'REST'
				],
				'getComponents' => [
						'path'=>'third/app/app/components',
						'method'=>'GET',
						'type' => 'REST'
				],
				'getApps' => [
						'path'=>'third/app/apps',
						'method'=>'GET',
						'type' => 'REST'
				],
		]
	];