@extends('layout')

@section('content')
	<h1><a href="#/">Crowdin Publish</a></h1>
	<p>A tool to publish the translations from Crowdin to translate.prestashop.com</p>
	<div ng-app="crowdinpublish" ng-view></div>
@stop
