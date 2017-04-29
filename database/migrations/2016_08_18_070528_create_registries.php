<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRegistries extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('registries', function(Blueprint $table)
		{
			$table->string('id', 36)->primary();
			$table->string('name', 50)->unique();
			$table->string('host', 255);
			$table->string('auth_user', 50);
			$table->string('auth_pwd', 125);
			$table->string('paas_api_url', 255);
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('registries');
	}

}
