<?php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\CdException;
use Illuminate\Support\Facades\Log;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\DB;
use App\Exceptions\PaasException;
/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/
	/**
	 * model registry
	 * 
	 * @author zaric.zhang
	 *
	 */
	class Registry extends Model {
		protected $table = 'registries';
		
		public $incrementing = false;
		
		protected $dates = ['deleted_at'];
		
		protected $fillable = [
				'id', 'name', 'host', 'auth_user', 'auth_pwd', 'paas_api_url'
		];
		
	}
	
	class Post extends Model {
		protected $table = 'posts';
	
		protected $dates = ['deleted_at'];
	
		protected $fillable = [
			'registry_id', 'user_id', 'user_name', 'build_num', 'status','image', 'tag', 'namespace', 'commands', 'base_image', 'docker_file', 'type'
		];
		const STATUS_PENDING = 0;
		const STATUS_IN_PROGRESS = 1;
		const STATUS_FAILURE = 2;
		const STATUS_ABORT = 4;
		const STATUS_SUCCESS = 8;
	}
	
	/*
	 |------------------------------------------------------------------------
	 | registry micro service
	 | rest api
	 | support JSON format response
	 |------------------------------------------------------------------------
	 | 定义rest api接口，指定路由规则
	 | @author: zaric.zahng@idevopscloud.com
	 | @date  : 2016-08-18
	 */
	
	/*
	 *######################
	 * registries api [main]
	 *######################
	 */
	
	/**
	 * response registries list
	 * 
	 */
	$app->get('registries', ['middleware' => ['auth', 'json_format'], function(Request $request) {
		if ($request->input('name')) {
			$results  = Registry::where('name', $request->name)->get()->each(function($row) {
				$row->setHidden(['auth_user', 'auth_pwd']);
			})->first();
		} else {
			$results = Registry::all()->each(function($row) {
				$row->setHidden(['auth_user', 'auth_pwd']);
			});
		}
		return response()->json($results);
	}]);

	/**
	 * request registries/{id} get registry info 
	 * if action: images, result append images info
	 * 
	 */
	$app->get ( 'registries/{id}', [ 'middleware' => ['auth', 'json_format'],function ($id, Request $request) {
			$registry = Registry::select ( 'id', 'name', 'paas_api_url', 'host' )->find ( $id );
			$posts = [];
			$images = [];
			if ($request->input ( 'action' ) == 'images') {
				try {
					$req_data = $request->all();
					if ($request->Input ( 'type' ) != 'app' && $request->Input ( 'type' ) != 'app_base') {
						unset($req_data['type']);
					}
					$data = do_request_paas ( "{$registry->paas_api_url}/images", 'GET', $req_data);
					if ($request->input('type') == 'push_img') {
						$posts = Post::where([
							'image'=>$request->input('image_name'), 
							'tag'=>$request->input('tag'), 
							'namespace'=>$request->input('namespace'),
							'type' => 'push_img',
							'registry_id' => $id
						])->where('status', '<>', 0)->orderBy('id', 'desc')->get()->toArray();
					}
					
				} catch (PaasException $e) {
					\Log::error($e);
					$data ['images'] = [];
				}
				$images = $data ['images'];
				
				if ($request->Input ( 'type' ) == 'lib') {
					
				} else if ($request->Input('image')) {
					foreach ($images as $key => $image) {
						if ($request->Input('image') == $image['name']) {
							foreach ( $image ['tags'] as $tag ) {
								if ($tag['name'] == $request->Input('tag')) {
									if ($request->input('type') == 'push_img') {
										return response ()->json ( ['image'=>$image, 'posts' => $posts]);
									}
									return response ()->json ( $image );
								}
							}
						}
					}
					if ($request->input('type') == 'push_img') {
						return response ()->json ( ['image'=>null, 'posts' => $posts]);
					}
					return response ()->json ( [] ); // image not exist
				} else { // registry-api builded image, get tag from db
					foreach ( $images as &$image ) {
						foreach ( $image ['tags'] as &$tag ) {
							$post = Post::select ( 'user_name', 'created_at' )->where ( [ 
									'image' => $image ['short_name'],
									'namespace' => $request->Input ( 'app_name' ),
									'tag' => $tag ['name'] 
							] )->first ();
							$post && $tag = array_merge ( $tag, $post->toArray () );
						}
					}
				}
				$registry->images = $images;
			}
			return response ()->json ( $registry );
		} 
	] );
	
	/**
	 * delete image with speicified version and app_name
	 * required type, app_name, name, version
	 */
	$app->delete('registries/{id}', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		$validator = Validator::make($request->all(), [
				'type' => 'required',
				'app_name' => 'required',
				'name' => 'required',
				'version' => 'required',
		]);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		if ($request->type == 'app_base') {
			$coms = with(new ApiProvider())->getComponents(['token' => $request->token, 
					'action' => 'deploy',
					'q' => "app_base/{$request->app_name}/{$request->name}:{$request->version}",
					'd_status' => 1,
					'd_is_deploy' => 1
			]);
			if ( count($coms) > 0) {
				$images = [];
				foreach ($coms as $com) {
					$images[] = "{$com['deploy']['instance']['name']}/{$com['name']}:{$com['version']}";
				}
				throw new \Exception(trans('exception.delete_be_refered_image', ['comp_images'=>implode(',', $images)]));
			}
		}
		$registry = Registry::select('id', 'name', 'paas_api_url')->findOrFail($id);
		$response = do_request_paas("{$registry->paas_api_url}/images", 'DELETE', [
			'type' => $request->type,
			'app_name' => $request->app_name,
			'name' => $request->name,
			'version' => $request->version
		]);
		Post::where(['image'=>$request->name, 'tag'=>$request->version, 'namespace'=>$request->app_name])->delete();
		return response()->json($response);
	}]);
	
	/**
	 * create a new registry
	 * required: host,name,auth_user,auth_pwd
	 *
	 */
	$app->post('registries', ['middleware' => ['auth', 'json_format'], function(Request $request){
		$validator = Validator::make($request->all(), [
				'host' => 'required',
				'name' => 'required|unique:registries,name',
				/*'auth_user' => 'required',
				'auth_pwd' => 'required',*/
				'paas_api_url' => 'required|url',
				]);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		$registry = new Registry($request->all());
		$registry->id = gen_uuid();
		$registry->save();
		return response()->json($registry->toArray());
	}]);

	/**
	 * build registry base image
	 * required: namespace,base_image, base_image_tag, image_name, image_tag, commands
	 * 
	 */
	$app->post('base_image', ['middleware' => ['auth', 'json_format'], function(Request $request) {
		$validate_rules = [
			'registry_id' => 'required|exists:registries,id',
			'namespace' => 'required',
			'base_image' => 'required',
			'base_image_tag' => 'required',
			'image_name' => 'required',
			'image_tag' => 'required',
			'commands' => 'required',
		];
		$validator = Validator::make($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		
		$registry = Registry::select('id', 'name', 'paas_api_url', 'host')->findOrFail($request->registry_id);
		$data = do_request("{$registry->paas_api_url}/images", 'GET', ['type'=> 'app_base', 'app_name'=>$request->namespace]);
		foreach ($data['images'] as $image) {
			if ($request->image_name == $image['short_name']) {
				if (isset($image['tags']) && in_array(['name'=>$request->image_tag], $image['tags'])) {
					throw new \Exception(trans('exception.image_already_exist'));
				}
			}
		}
		if ($request->input ( 'callback' )) { // 用户自定义的callback
			$callback = $request->callback;
		} else { // 服务自动生成callback
			$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
			$post = new Post ( [ 
				'registry_id' => $request->registry_id,
				'user_id' => $user ['id'],
				'user_name' => $user ['name'],
				'status' => 0,
				'namespace' => $request->namespace,
				'image' => $request->image_name,
				'tag' => $request->image_tag,
				'base_image' => "{$request->base_image}:{$request->base_image_tag}",
				'commands' => $request->commands,
				'type'=>'base_img'
			] );
			$post->save();
			$callback_host = config('api.idevops_host') . "/third/registry/"; //对外服务的url地址，部署 时候设置
			$callback = "{$callback_host}/registries/{$request->registry_id}/postback?post_id={$post->id}&token={$request->token}";
		}
		
		$registry = Registry::findOrFail($request->registry_id);
		$data = [
			'img_in' => "{$registry->host}/{$request->base_image}:{$request->base_image_tag}",
			'img_out' => "{$registry->host}/app_base/{$request->namespace}/{$request->image_name}:{$request->image_tag}",
			'repo_usr' => $registry->auth_user ? :"",
			'repo_pwd' => $registry->auth_pwd ? :"",
			'commands' => $request->commands,
			'callback' => $callback
		];
		try {
			$user_pwd = config('cd.user').":".config('cd.pwd');
			$cd_resp = do_request_cd(config('cd.host')."/api/v1.0/base_img", "POST", $data, null, $user_pwd);
		} catch (CdException $e) {
			\Log::error($e);
			throw new \Exception(trans("exception.request_cd_error"));
		} catch (\Exception $e) {
			throw $e;
		}
		return response()->json([]);
	}]);
	
	/**
	 * build component image
	 */
	$app->post('component_image', ['middleware' => ['auth', 'json_format'], function(Request $request) {
		$validate_rules = [
			'registry_id' => 'required|exists:registries,id',
			'namespace' => 'required',
			'base_image' => 'required',
			'image_name' => 'required',
			'image_tag' => 'required',
			'git.addr' => 'required',
			'git.tag' => 'required',
			'start_path' => 'required',
			'token' => 'required',
		];
		$validator = Validator::make($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		
		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
		if ($request->input ( 'callback' )) { // 用户自定义的callback
			$callback = $request->callback;
		} else {
			$post = Post::firstOrNew ( [
				'registry_id' => $request->registry_id,
				'user_id' => $user ['id'],
				'user_name' => $user ['name'],
				'status' => 8,
				'namespace' => $request->namespace,
				'image' => $request->image_name,
				'tag' => $request->image_tag,
				'base_image' => "{$request->base_image}",
				'type'=>'comp_img'
			] );
			if ($post->id && $request->input('force_build') != 1) { // 已存在的成功构建,并且非强制性构建,直接返回
				return response()->json([]);
			}
			$post->status = 0;
			$post->save();
			$callback_host = config('api.idevops_host') . "/third/registry/"; //对外服务的url地址，部署 时候设置
			$callback = "{$callback_host}/registries/{$request->registry_id}/postback?post_id={$post->id}&token={$request->token}";
		}
		
		$registry = Registry::findOrFail($request->registry_id);
		$data = [
			'img_in' => "{$registry->host}/{$request->base_image}",
			'img_out' => "{$registry->host}/app/{$request->namespace}/{$request->image_name}:{$request->image_tag}",
			'repo_usr' => $registry->auth_user ? :"",
			'repo_pwd' => $registry->auth_pwd ? :"",
			'git_addr' => "{$request->git['addr']}",
			'git_tag' => $request->git['tag'],
			'build_path' => $request->input('build_path'),
			'start_path' => $request->start_path,
			'callback' => $callback
		];
		try {
			$user_pwd = config('cd.user').":".config('cd.pwd');
			$cd_resp = do_request_cd(config('cd.host')."/api/v1.0/comp_img", "POST", $data, null, $user_pwd);
		} catch (CdException $e) {
			\Log::error($e);
			throw new \Exception(trans("exception.request_cd_error"));
		} catch (\Exception $e) {
			throw $e;
		}
		return response()->json([]);
	}]);

	/**
	 * build job post back
	 * required:id, post_id, build_num
	 */
	$app->post('registries/{id}/postback', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		$validate_rules = [
			'id' => 'exists:registries,id',
			'post_id' => 'required',
			'build_num' => 'required',
		];
		$validator = Validator::make($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		$post = Post::find($request->post_id);
		if ($request->input('df')) {
			$post->docker_file = $request->df;
		}
		if ($request->input('status')) {
			switch ($request->status) {
				case 'SUCCESS':
					$registry = Registry::find($id);
					$post->status = Post::STATUS_SUCCESS;
					// push to other registry
					/*if ($post->type == 'comp_img') {
						$data = with(new ApiProvider())->getApps(['token' => $request->token, 'name' => $post->namespace]);
						if (count($data['apps']) > 0) {
							$app = $data['apps'][0];
							foreach ($app['node_groups'] as $key => $ng) {
								$cluster = $ng['cluster'];
								$out_registry = Registry::find($cluster['registry_id']);
								$data = [
									'img_in' => "{$registry->host}/app/{$post->namespace}/{$post->image}:{$post->tag}",
									'img_out' => "{$out_registry->host}/app/{$post->namespace}/{$post->image}:{$post->tag}",
									'in_repo_usr' => $registry->auth_user,
									'in_repo_pwd' => $registry->auth_pwd,
									'out_repo_usr' => $out_registry->auth_user,
									'out_repo_pwd' => $out_registry->auth_pwd,
									'callback' => ''
								];
								$user_pwd = config('cd.user').":".config('cd.pwd');
								try {
									$resp = do_request_cd(config('cd.host')."/api/v1.0/push_img", "POST", $data, null, $user_pwd);
									\Log::info("cd response", $resp);
								} catch (\Exception $e) {
									\Log::error($e);
								}
							}
						}
					}*/
					break;
				case 'IN_PROGRESS':
					$post->status = Post::STATUS_IN_PROGRESS;
					break;
				case 'FAILURE':
					$post->status = Post::STATUS_FAILURE;
					break;
			}
		} 
		$post->build_num = $request->build_num;
		$post->save();
		return response()->json([]);
	}]);

	$app->post('registries/{id}/pushimage', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		$validate_rules = [
			'id' => 'exists:registries,id',
			'image' => 'required',
			'tag' => 'required'
		];
		$validator = Validator::make($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}

		$image = $request->Input('image');
		$tag = $request->Input('tag');
		$build = $request->Input('build');
		$platform_registry = Registry::where('name', 'platform')->first();

		if ($build == 'false') {
			$image_in = $request->Input('image_in');
		} else {
			$image_in = "{$platform_registry->host}/{$image}:{$tag}";
		}

		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
		$post = new Post ( [ 
				'registry_id' => $id,
				'user_id' => $user ['id'],
				'user_name' => $user ['name'],
				'image' => $request->Input('image_name'),
				'tag' => $tag,
				'namespace' => $request->Input('namespace'),
				'status' => 0,
				'type'=>'push_img'
			] );
		$post->save();

		$registry = Registry::find($id);
		$callback_host = config('api.idevops_host') . "/third/registry/"; //对外服务的url地址，部署 时候设置
		$callback = "{$callback_host}/registries/{$id}/postback?post_id={$post->id}&token={$request->token}";

		$data = [
			'img_in' => $image_in,
			'img_out' => "{$registry->host}/{$image}:{$tag}",
			'in_repo_usr' => $platform_registry->auth_user ? :"",
			'in_repo_pwd' => $platform_registry->auth_pwd ? :"",
			'out_repo_usr' => $registry->auth_user ? :"",
			'out_repo_pwd' => $registry->auth_pwd ? :"",
			'callback' => $callback
		];
		$user_pwd = config('cd.user').":".config('cd.pwd');
		try {
			$resp = do_request_cd(config('cd.host')."/api/v1.0/push_img", "POST", $data, null, $user_pwd);
			\Log::info("cd response", $resp);
		} catch (\Exception $e) {
			\Log::error($e);
		}
		return response()->json($post);
	}]);
	
	/**
	 * get posts list with user id
	 * 
	 */
	$app->get('posts', ['middleware' => ['auth', 'json_format'], function(Request $request) {
		$posts = [];
		$action = $request->input('action');
		if ($action == 'search') {
			$query = DB::table(with(new Post)->getTable());
			$request->input('image') && $query->where('image', $request->input('image'));
			$request->input('tag') && $query->where('tag', $request->input('tag'));
			$request->input('namespace') && $query->where('namespace', $request->input('namespace'));
			$request->input('status') && $query->where('status', $request->input('status'));
			$request->input('type') && $query->where('type', $request->input('type'));
			$request->input('base_image') && $query->where('base_image', $request->input('base_image'));
			if ($request->input('order_by')) {
				$orders = explode(',', $request->input('order_by'));
				$query->orderBy($orders[0], isset($orders[1]) ? $orders[1]:'desc');
			} else {
				$query->orderBy('id', 'desc');
			}
			$posts = $query->paginate(15);
			
		// user's post
		} else {
			$time = date('Y-m-d H:i:s', time() - 3600 * 8);
			$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
			$query = Post::where('user_id', $user['id'])->where('created_at', '>=', $time)->orderBy('id', 'desc');
			if ($request->input('type')) {
				$query->where('type', $request->input('type'));
			}
			$posts = $query->paginate(15);
		}
		
		return response()->json($posts);
	}]);
	
	/**
	 * show post detail
	 * 
	 */
	$app->get('posts/{id}', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		$post = Post::find($id);
		return response()->json($post);
	}]);
	
	/**
	 * delete post
	 * @date 2016-10-10
	 */
	$app->delete('posts/{id}', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		Post::findOrFail($id)->delete();
		return response()->json([]);
	}]);
	
	/**
	 * stop build job
	 * only in_progress job can be aborted
	 */
	$app->post('posts/{id}/stop', ['middleware' => ['auth', 'json_format'], function($id, Request $request) {
		$post = Post::find($id);
		$validate_rules = [
			'type' => 'required'
		];
		$validator = Validator::make($request->all(), $validate_rules, ['type.required' => trans('validation.job_type_required')]);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		
		$data = [
			'job' => $request->type,
			'build_num' => $post->build_num,
		];
		DB::beginTransaction();
		try {
			$user_pwd = config('cd.user').":".config('cd.pwd');
			$cd_resp = do_request_cd(config('cd.host')."/api/v1.0/stop_build", "GET", $data, false, $user_pwd);
			$post = Post::find($id);
			if ($post->status == Post::STATUS_IN_PROGRESS) {
				$post->status = Post::STATUS_ABORT;
				$post->save();
			}
		} catch (CdException $e) {
			\Log::error($e);
			throw new \Exception(trans("exception.stop_build_error"));
			DB::rollBack();
		} catch (\Exception $e) {
			throw $e;
			DB::rollBack();
		}
		DB::commit();
		return response()->json([]);
	}]);
	
	/**
	 * get console log
	 * cd-api Transfer-Encoding:chunked
	 */
	$app->get('console/{id}', function($id, Request $request) {
		$user_pwd = config('cd.user').":".config('cd.pwd');
		$start = $request->input('start', 0);
		try {
			$response = do_request_cd ( config ( 'cd.host' ) . "/api/v1.0/console", "GET", [ 
				'job' => $request->input('type', 'base_img'),
				'build_num' => $id,
				'start' => $start 
			], false, $user_pwd, true );
		} catch (CdException $e) {
			return response()->json($e->getMessage(), $e->getCode());
		}
		return response()->json($response['content'], $response['code'], $response['header']);
	});
		
	