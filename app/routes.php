<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/
Route::get('/', 'HomeController@index');


/**
 * API
 */

Route::get('/versions', function() {
	return Response::json(PSVersion::getList());
});

Route::get('/versions/{version_number}', function($version_number) {
	return Response::json(PSVersion::getVersion($version_number));
});

Route::post('/versions/{version_number}', function($version_number) {
	$data = Input::all();

	if (Input::hasFile('archive')) {
		$data['archive'] = Input::file('archive');
	}

	return Response::json(PSVersion::createOrUpdateVersion($version_number, $data));
});
