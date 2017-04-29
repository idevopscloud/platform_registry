<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePosts extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('posts', function(Blueprint $table)
		{
			$table->increments('id');
			$table->tinyInteger('status')->default(0);
			$table->integer('user_id');
			$table->string('user_name', 50);
			$table->text('commands');
			$table->text('docker_file');
			$table->string('image');
			$table->string('tag');
			$table->string('namespace');
			$table->string('base_image');
			$table->integer('build_num');
			$table->string('registry_id', 36);
			$table->string('type', 25);
			$table->timestamps();
			$table->foreign('registry_id')->references('id')->on('registries')->onDelete('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('posts');
	}

}
