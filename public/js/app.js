var app = angular.module('crowdinpublish', ['ngRoute']);

app.config(function($logProvider){
    $logProvider.debugEnabled(true);
});

app.directive('djfmFileInput', function() {
	return {
		restrict: 'A',
		link: function(scope, element, attrs) {

			attrs.djfmFileInput
			angular.element(element).on('change', function() {
				scope.$eval(attrs.djfmFileInput + ' = ' + 'files', {files: element[0].files})
			});
		}
	};
});

function toVersion(v, obj) {
	obj.version_number = v;
	obj.version_header = parseInt(obj.version_header, 10);
	return obj;
}

app.config(['$routeProvider', function($routeProvider) {
	$routeProvider
	.when('/', {
		templateUrl: 'views/index.html',
		controller: 'IndexCtrl'
	})
	.when('/versions/:version_number/edit', {
		templateUrl: 'views/edit_version.html',
		controller: 'EditVersionCtrl'
	})
	.otherwise({
		redirectTo: '/'
	});
}]);

app.controller('IndexCtrl', function($scope, $http) {
	$http.get('versions').then(function(resp) {
		if (resp.data.success) {
			$scope.versions = resp.data.data;
		}
		else {
			alert('OOPS!');
		}
	})
});

app.controller('NewVersionCtrl', function($scope, $http) {
	$scope.version = {};
});

app.controller('EditVersionCtrl', function($scope, $http, $injector, $routeParams) {

	injector = $injector;

	$http.get('versions/' + $routeParams.version_number).then(function(resp) {
		
		$scope.version_number = $routeParams.version_number;

		if (resp.data.success) {
			$scope.version = toVersion($scope.version_number, resp.data.data);
		}
		else {
			alert('OOPS!');
		}
	});

	$scope.submitVersionForm = function()
	{
		var data = new FormData();

		data.append('version_header', $scope.version.version_header);
		if ($scope.version.archive && $scope.version.archive.length === 1) {
			data.append('archive', $scope.version.archive[0]);
		}

		$http.post('versions/' + $scope.version.version_number, data, {
			headers: {'Content-Type': undefined},
			transformRequest: function(data) {
				return data;
			}
		});
	}
});
