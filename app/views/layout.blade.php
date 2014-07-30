<html>
	<head>
		<title>Crowdin Publish</title>
		{{HTML::style('vendor/bootstrap-3.2.0-dist/css/bootstrap.css')}}
		{{HTML::style('vendor/bootstrap-3.2.0-dist/css/bootstrap-theme.css')}}
		{{HTML::style('css/main.css')}}
		{{HTML::script('vendor/angular.js')}}
		{{HTML::script('vendor/angular-route.js')}}
	</head>
	<body>
		<div class="container">
			@yield('content')
		</div>
		{{HTML::script('js/app.js')}}
	</body>
</html>
