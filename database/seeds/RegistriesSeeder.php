<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RegistriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
    	if (DB::table('registries')->where('name', 'platform')->exists() == false) {
	    	DB::table('registries')->insert([
	    		'id' => gen_uuid(),
		    	'name' => 'platform',
		    	'host' => env('REGISTRY_HOST', 'aws-seoul.repo.idevopscloud.com:5000'),
		    	'auth_user' => env('REGISTRY_AUTH_USER', ''),
		    	'auth_pwd' => env('REGISTRY_AUTH_PWD', ''),
		    	'paas_api_url' => env('REGISTRY_PAAS_API_URL', 'http://ap10.idevopscloud.com:12406'),
		    	'created_at' => date('Y-m-d H:i:s'),
		    	'updated_at' => date('Y-m-d H:i:s'),
	    	]);
    	}
    }
}
